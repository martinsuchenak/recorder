package main

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

type config struct {
	listenAddr    string
	serviceToken  string
	tokenSecret   string
	uploadDir     string
	baseURL       string
	maxUploadSize int64
	tokenTTL      time.Duration
}

type tokenRequest struct {
	UploadID string                 `json:"uploadId"`
	UserID   string                 `json:"userId"`
	FileSize int64                  `json:"fileSize"`
	MimeType string                 `json:"mimeType"`
	Metadata map[string]interface{} `json:"metadata"`
}

type tokenResponse struct {
	Token     string `json:"token"`
	ExpiresAt int64  `json:"expiresAt"`
}

type uploadClaims struct {
	UploadID string
	UserID   string
	ExpiresAt int64
}

func main() {
	cfg := config{
		listenAddr:    getEnv("LISTEN_ADDR", ":8080"),
		serviceToken:  getEnv("SERVICE_TOKEN", "change-me-service-token"),
		tokenSecret:   getEnv("TOKEN_SECRET", "change-me-token-secret"),
		uploadDir:     getEnv("UPLOAD_DIR", "./uploads"),
		baseURL:       getEnv("BASE_URL", "http://localhost:8080"),
		maxUploadSize: int64(500 * 1024 * 1024),
		tokenTTL:      15 * time.Minute,
	}

	if err := os.MkdirAll(cfg.uploadDir, 0755); err != nil {
		log.Fatalf("failed to create upload dir: %v", err)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/token", cfg.handleToken)
	mux.HandleFunc("/upload", cfg.handleUpload)
	mux.HandleFunc("/health", cfg.handleHealth)
	mux.Handle("/videos/", http.StripPrefix("/videos/", http.FileServer(http.Dir(cfg.uploadDir))))

	handler := withCORS(mux)

	log.Printf("Upload microservice listening on %s", cfg.listenAddr)
	log.Fatal(http.ListenAndServe(cfg.listenAddr, handler))
}

func (c *config) handleToken(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})
		return
	}

	authHeader := r.Header.Get("Authorization")
	if authHeader != "Bearer "+c.serviceToken {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})
		return
	}

	body, err := io.ReadAll(io.LimitReader(r.Body, 1024*1024))
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "failed to read body"})
		return
	}

	var req tokenRequest
	if err := json.Unmarshal(body, &req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})
		return
	}

	if req.UploadID == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "missing uploadId"})
		return
	}

	expiresAt := time.Now().Add(c.tokenTTL).Unix()
	token, err := c.generateToken(req.UploadID, req.UserID, expiresAt)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "failed to generate token"})
		return
	}

	pendingDir := filepath.Join(c.uploadDir, ".pending")
	os.MkdirAll(pendingDir, 0755)
	metaPath := filepath.Join(pendingDir, req.UploadID+".json")
	metaData, _ := json.Marshal(map[string]interface{}{
		"uploadId": req.UploadID,
		"userId":   req.UserID,
		"fileSize": req.FileSize,
		"mimeType": req.MimeType,
		"metadata": req.Metadata,
	})
	os.WriteFile(metaPath, metaData, 0644)

	writeJSON(w, http.StatusOK, tokenResponse{
		Token:     token,
		ExpiresAt: expiresAt,
	})
}

func (c *config) handleUpload(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})
		return
	}

	authHeader := r.Header.Get("Authorization")
	if !strings.HasPrefix(authHeader, "Bearer ") {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "missing token"})
		return
	}
	tokenStr := strings.TrimPrefix(authHeader, "Bearer ")

	claims, err := c.parseToken(tokenStr)
	if err != nil {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "invalid or expired token"})
		return
	}

	if time.Now().Unix() > claims.ExpiresAt {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "token expired"})
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, c.maxUploadSize)

	if err := r.ParseMultipartForm(32 << 20); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "failed to parse form: " + err.Error()})
		return
	}

	file, header, err := r.FormFile("video")
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "missing video file"})
		return
	}
	defer file.Close()

	ext := filepath.Ext(header.Filename)
	if ext == "" {
		ext = ".webm"
	}
	fileName := claims.UploadID + ext
	filePath := filepath.Join(c.uploadDir, fileName)

	dst, err := os.Create(filePath)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "failed to save file"})
		return
	}
	defer dst.Close()

	written, err := io.Copy(dst, file)
	if err != nil {
		os.Remove(filePath)
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "failed to write file"})
		return
	}

	metadataJSON := r.FormValue("metadata")
	if metadataJSON != "" {
		metaPath := filePath + ".meta.json"
		var combined map[string]interface{}
		json.Unmarshal([]byte(metadataJSON), &combined)
		if combined == nil {
			combined = make(map[string]interface{})
		}
		combined["storedSize"] = written
		combined["storedAt"] = time.Now().UTC().Format(time.RFC3339)
		metaBytes, _ := json.MarshalIndent(combined, "", "  ")
		os.WriteFile(metaPath, metaBytes, 0644)
	}

	pendingMeta := filepath.Join(c.uploadDir, ".pending", claims.UploadID+".json")
	os.Remove(pendingMeta)

	videoURL := c.baseURL + "/videos/" + claims.UploadID + ext

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"url": videoURL,
		"id":  claims.UploadID,
	})

	log.Printf("uploaded %s (%d bytes) for user %s", claims.UploadID, written, claims.UserID)
}

func (c *config) handleHealth(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (c *config) generateToken(uploadID, userID string, expiresAt int64) (string, error) {
	payload := fmt.Sprintf("%s:%s:%d", uploadID, userID, expiresAt)
	mac := hmac.New(sha256.New, []byte(c.tokenSecret))
	mac.Write([]byte(payload))
	sig := hex.EncodeToString(mac.Sum(nil))
	return fmt.Sprintf("%s:%s", payload, sig), nil
}

func (c *config) parseToken(token string) (*uploadClaims, error) {
	parts := strings.SplitN(token, ":", 4)
	if len(parts) != 4 {
		return nil, fmt.Errorf("invalid token format")
	}

	uploadID := parts[0]
	userID := parts[1]
	expiresAtStr := parts[2]
	signature := parts[3]

	expiresAt, err := strconv.ParseInt(expiresAtStr, 10, 64)
	if err != nil {
		return nil, fmt.Errorf("invalid expiration")
	}

	payload := fmt.Sprintf("%s:%s:%d", uploadID, userID, expiresAt)
	mac := hmac.New(sha256.New, []byte(c.tokenSecret))
	mac.Write([]byte(payload))
	expectedSig := hex.EncodeToString(mac.Sum(nil))

	if !hmac.Equal([]byte(signature), []byte(expectedSig)) {
		return nil, fmt.Errorf("invalid signature")
	}

	return &uploadClaims{
		UploadID:  uploadID,
		UserID:    userID,
		ExpiresAt: expiresAt,
	}, nil
}

func withCORS(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "POST, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func writeJSON(w http.ResponseWriter, status int, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(data)
}

func getEnv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func init() {
	_ = rand.Reader
}

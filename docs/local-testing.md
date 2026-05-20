# Local Testing Guide

Test the full upload flow locally: Recorder (PHP) → PHP (auth + preflight) → Go (token + storage).

## Prerequisites

- **Node.js** 18+ and npm (only needed to build the recorder)
- **PHP** 8.0+ with the `curl` extension
- **Go** 1.21+

## Architecture

```
Browser
  │
  │ 1. POST /upload.php (preflight, with PHP session cookie)
  ▼
PHP Server
  │  validates session, checks file size
  │ 2. POST /token (service-to-service, shared secret)
  ▼
Go Microservice
  │  issues short-lived HMAC token (15 min TTL)
  │  returns { token, uploadUrl, uploadId }
  ▼
PHP Server
  │  returns { token, uploadUrl, uploadId } to browser
  ▼
Browser
  │ 3. POST /upload directly to Go microservice
  │    with Bearer token + video + metadata
  ▼
Go Microservice
  │  validates token, stores video + metadata JSON
  │  returns { url, id }
  ▼
Browser shows the video URL
```

## Quick Start

You need 2 terminals (3 if developing the recorder).

### Terminal 1: Go Microservice

```bash
cd server/go
SERVICE_TOKEN=change-me-service-token TOKEN_SECRET=change-me-token-secret go run main.go
```

Verify: `curl http://localhost:8080/health` → `{"status":"ok"}`

### Terminal 2: Build Recorder + Start PHP

```bash
# Build the recorder (only needed once, or after code changes)
VITE_BASE=/recorder/ npm run build
rm -rf server/php/recorder && cp -r dist server/php/recorder

# Start the PHP server
cd server/php
SERVICE_TOKEN=change-me-service-token php -S localhost:8000 router.php
```

> **Important:** `SERVICE_TOKEN` must match on both PHP and Go. If you change it on one, change it on the other.

### Use It

1. Open [http://localhost:8000](http://localhost:8000)
2. Sign in with any user ID
3. Click the red record button (bottom-right)
4. Allow the popup, share your screen, record
5. Click **Upload** in the recorder
6. The video URL appears in the dashboard

### Verify on disk

```bash
ls -la server/go/uploads/
cat server/go/uploads/*.meta.json
```

## Development Mode (Vite)

If you're actively developing the recorder, skip the build step and use Vite directly.

### Terminal 3: Vite Dev Server

```bash
npm run dev
```

### Switch the PHP host app to use Vite

Edit `server/php/index.php` — change the recorder URL near the top:

```php
// Built version (default):
$recorderUrl = '/recorder/';

// Development:
$recorderUrl = 'http://localhost:5173';
```

In dev mode the recorder runs on port 5173 and PHP on port 8000 — different origins. The PHP endpoints handle CORS automatically by reflecting the `Origin` header. No extra configuration needed.

You also need to set the upload config in the recorder's `index.html` since the config can't be injected cross-origin:

```html
<script>
  window.__RECORDER_UPLOAD_CONFIG__ = window.__RECORDER_UPLOAD_CONFIG__ || {
    preflightUrl: '/upload.php',
    timeout: 300000
  };
</script>
```

This is already in the project's `index.html`. It uses `/upload.php` (relative), which works for both same-origin (built) and cross-origin (Vite dev with CORS) modes.

## How It Works

### File Structure

```
server/php/
├── config.php       # Environment config (tokens, URLs, paths)
├── index.php        # Host web app (dashboard + login + record button)
├── upload.php       # Preflight endpoint (validates session, gets token from Go)
├── router.php       # PHP built-in server router (adds COOP/COEP headers)
└── recorder/        # Built recorder assets (from npm run build)
    ├── index.html
    └── assets/

server/go/
├── main.go          # Upload microservice
├── uploads/         # Stored videos + metadata
└── go.mod
```

### Why a popup, not an iframe?

The recorder uses the Document Picture-in-Picture API, which only works in top-level browsing contexts. It cannot run inside an iframe. The host app opens the recorder via `window.open()`.

### Why COOP/COEP headers?

The browser requires `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Embedder-Policy: require-corp` for `MediaStreamTrackProcessor` (used by the recorder). The `router.php` adds these headers for all `/recorder/` requests.

### Auth Flow

```
1. User logs in at index.php → PHP session created
2. User clicks Record → popup opens /recorder/
3. User clicks Upload in recorder:
   a. Browser POSTs to /upload.php (with session cookie)
   b. PHP validates session → requests token from Go
   c. Go returns signed HMAC token (15 min TTL)
   d. PHP returns { token, uploadUrl } to browser
   e. Browser uploads video directly to Go with Bearer token
   f. Go validates token, stores file, returns { url, id }
   g. Recorder shows the URL, posts RECORDER_UPLOADED to host app
```

No API keys ever touch the browser. The only token the browser sees is a short-lived upload token issued by Go after PHP confirms the user is authenticated.

## postMessage API

The recorder sends events to its parent/opener window:

| Event | Data | When |
|-------|------|------|
| `RECORDER_STARTED` | `{ type: 'RECORDER_STARTED' }` | Recording starts |
| `RECORDER_STOPPED` | `{ type: 'RECORDER_STOPPED' }` | Recording stops, modal opens |
| `RECORDER_UPLOADED` | `{ type: 'RECORDER_UPLOADED', url, id }` | Upload completes |

The recorder listens for commands from the parent:

| Command | Data | Effect |
|---------|------|--------|
| `RECORDER_START` | `{ type: 'RECORDER_START' }` | Starts recording |
| `RECORDER_STOP` | `{ type: 'RECORDER_STOP' }` | Stops recording |

Example — start recording from the host app:

```js
var popup = window.open('/recorder/', 'recorder', 'width=720,height=540');
popup.postMessage({ type: 'RECORDER_START' }, '*');
```

## Troubleshooting

### 502 Bad Gateway on upload

PHP can't reach the Go microservice. Check:

1. Go is running: `curl http://localhost:8080/health`
2. `SERVICE_TOKEN` matches on both PHP and Go
3. `UPLOAD_MICROSERVICE_URL` points to Go (default: `http://localhost:8080`)

### Popup blocked

Allow popups for `localhost:8000` in your browser. The recorder must open as a popup (not an iframe) because the Document Picture-in-Picture API requires a top-level browsing context.

### "Not authenticated" on upload

Log in first at `http://localhost:8000`. The PHP session cookie must be present when the preflight request is sent.

### 404 on video URL

Make sure the Go microservice is running. It serves uploaded files at `/videos/<id>.webm`.

### MIME type errors (JS/CSS not loading)

Rebuild and recopy the recorder assets:

```bash
VITE_BASE=/recorder/ npm run build
rm -rf server/php/recorder && cp -r dist server/php/recorder
```

### Upload button not visible

The button appears when `__RECORDER_UPLOAD_CONFIG__.preflightUrl` is set. Verify in the popup's browser console:

```js
console.log(window.__RECORDER_UPLOAD_CONFIG__);
```

## Environment Variables Reference

### Go Microservice

| Variable | Default | Description |
|----------|---------|-------------|
| `LISTEN_ADDR` | `:8080` | Listen address |
| `SERVICE_TOKEN` | `change-me-service-token` | Token used by PHP to authenticate with Go |
| `TOKEN_SECRET` | `change-me-token-secret` | HMAC secret for signing upload tokens |
| `UPLOAD_DIR` | `./uploads` | Directory to store uploaded videos |
| `BASE_URL` | `http://localhost:8080` | Public base URL for video URLs |

### PHP Server

| Variable | Default | Description |
|----------|---------|-------------|
| `SERVICE_TOKEN` | `change-me-service-token` | Must match Go's `SERVICE_TOKEN` |
| `UPLOAD_MICROSERVICE_URL` | `http://localhost:8080` | URL of the Go microservice |

## Production Deployment Notes

- **Change all default secrets** (`SERVICE_TOKEN`, `TOKEN_SECRET`)
- **Put Go behind a reverse proxy** (nginx/caddy) with TLS
- **PHP and Go should not be publicly accessible** — the Go microservice should be on an internal network
- **Set `BASE_URL`** to your public CDN or file server URL
- **Add upload rate limiting** per user in the PHP layer
- **Add file cleanup** — old uploads should be purged or moved to cold storage
- **Use nginx/apache** instead of PHP's built-in server — configure COOP/COEP headers for `/recorder/`

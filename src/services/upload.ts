import type { RecordingMetadata } from './metadata';

export type PreflightResponse = {
  token: string;
  uploadUrl: string;
  uploadId: string;
};

export type UploadResponse = {
  url: string;
  id: string;
};

export type UploadProgress = {
  loaded: number;
  total: number;
  percent: number;
};

export type UploadOptions = {
  preflightUrl: string;
  metadata: RecordingMetadata;
  onProgress?: (progress: UploadProgress) => void;
  timeout?: number;
};

export class UploadError extends Error {
  constructor(
    message: string,
    public statusCode?: number,
    public body?: unknown,
  ) {
    super(message);
    this.name = 'UploadError';
  }
}

export const requestPreflight = async (
  preflightUrl: string,
  metadata: RecordingMetadata,
): Promise<PreflightResponse> => {
  const response = await fetch(preflightUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      fileSize: metadata.fileSize,
      mimeType: metadata.mimeType,
      metadata,
    }),
    credentials: 'include',
  });

  if (!response.ok) {
    const body = await response.text();
    throw new UploadError(
      `Preflight failed: ${response.status}`,
      response.status,
      body,
    );
  }

  return response.json();
};

export const uploadVideo = (
  blob: Blob,
  options: UploadOptions,
): Promise<UploadResponse> => {
  const { preflightUrl, metadata, onProgress, timeout = 0 } = options;

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();

    if (timeout > 0) {
      xhr.timeout = timeout;
    }

    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable && onProgress) {
        onProgress({
          loaded: event.loaded,
          total: event.total,
          percent: Math.round((event.loaded / event.total) * 100),
        });
      }
    });

    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          resolve(JSON.parse(xhr.responseText));
        } catch {
          reject(new UploadError('Invalid server response', xhr.status));
        }
      } else {
        reject(
          new UploadError(
            `Upload failed: ${xhr.status}`,
            xhr.status,
            xhr.responseText,
          ),
        );
      }
    });

    xhr.addEventListener('error', () =>
      reject(new UploadError('Network error during upload')),
    );

    xhr.addEventListener('timeout', () =>
      reject(new UploadError('Upload timed out')),
    );

    requestPreflight(preflightUrl, metadata)
      .then((preflight) => {
        const formData = new FormData();
        formData.append('video', blob, `recording-${preflight.uploadId}.webm`);
        formData.append('metadata', JSON.stringify(metadata));
        formData.append('uploadId', preflight.uploadId);

        xhr.open('POST', preflight.uploadUrl);
        xhr.setRequestHeader('Authorization', `Bearer ${preflight.token}`);
        xhr.send(formData);
      })
      .catch(reject);
  });
};

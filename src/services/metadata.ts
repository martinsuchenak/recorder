export type RecordingMetadata = {
  timestamp: string;
  userAgent: string;
  platform: string;
  language: string;
  viewport: { width: number; height: number };
  screen: { width: number; height: number; pixelRatio: number };
  url: string;
  referrer: string;
  fileSize: number;
  mimeType: string;
  custom?: Record<string, unknown>;
};

export const collectMetadata = (
  blob: Blob,
  custom?: Record<string, unknown>,
): RecordingMetadata => ({
  timestamp: new Date().toISOString(),
  userAgent: navigator.userAgent,
  platform: navigator.platform,
  language: navigator.language,
  viewport: {
    width: window.innerWidth,
    height: window.innerHeight,
  },
  screen: {
    width: window.screen.width,
    height: window.screen.height,
    pixelRatio: window.devicePixelRatio,
  },
  url: window.location.href,
  referrer: document.referrer,
  fileSize: blob.size,
  mimeType: blob.type,
  custom,
});

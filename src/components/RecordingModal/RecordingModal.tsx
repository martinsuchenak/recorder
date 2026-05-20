import { useEffect, useRef, useState } from 'react';
import { FFmpeg } from '@ffmpeg/ffmpeg';
import { fetchFile, toBlobURL } from '@ffmpeg/util';
import Button from '@mui/material/Button';

import { useUploadConfig } from 'contexts/uploadConfig';
import { collectMetadata } from 'services/metadata';
import { postToParent } from 'services/postMessage';
import { UploadError, uploadVideo } from 'services/upload';
import type { UploadProgress } from 'services/upload';

import styles from './RecordingModal.module.css';

type UploadState =
  | { status: 'idle' }
  | { status: 'preflighting' }
  | { status: 'uploading'; progress: UploadProgress }
  | { status: 'success'; url: string }
  | { status: 'error'; message: string };

type RecordingModalProps = {
  isOpen: boolean;
  onClose: () => void;
  recordingBlob: Blob | null;
};

export const RecordingModal = ({
  isOpen,
  onClose,
  recordingBlob,
}: RecordingModalProps) => {
  const [ffmpegStatus, setFfmpegStatus] = useState<
    'idle' | 'loading' | 'converting'
  >('idle');
  const [progress, setProgress] = useState(0);
  const [statusMessage, setStatusMessage] = useState('');
  const [uploadState, setUploadState] = useState<UploadState>({
    status: 'idle',
  });
  const ffmpegRef = useRef<FFmpeg>();
  const uploadConfig = useUploadConfig();

  useEffect(() => {
    const ffmpeg = new FFmpeg();
    ffmpegRef.current = ffmpeg;

    ffmpeg.on('log', ({ message }) => {
      console.log('FFmpeg log:', message);
      if (message.includes('configuration')) {
        setStatusMessage('Initializing encoder...');
      }
    });

    ffmpeg.on('progress', ({ progress }) => {
      console.log('FFmpeg progress:', progress);
      setFfmpegStatus('converting');
      const normalizedProgress = Math.abs(progress);
      const startValue = 2500000;
      const percentage = Math.min(
        100,
        Math.max(
          0,
          Math.round(
            (1 - (startValue - normalizedProgress) / startValue) * 100,
          ),
        ),
      );
      setProgress(percentage);
      setStatusMessage(`Converting video... ${percentage}%`);
    });

    return () => {
      ffmpeg.off('log', () => {});
      ffmpeg.off('progress', () => {});
    };
  }, []);

  if (!isOpen || !recordingBlob) return null;

  const downloadWebm = () => {
    const url = URL.createObjectURL(recordingBlob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'recording.webm';
    link.click();
    URL.revokeObjectURL(url);
    onClose();
  };

  const convertToMp4 = async () => {
    if (!ffmpegRef.current) return;

    setFfmpegStatus('loading');
    setStatusMessage('Loading FFmpeg libraries...');
    setProgress(0);

    const ffmpeg = ffmpegRef.current;

    try {
      const baseURL = 'https://unpkg.com/@ffmpeg/core@0.12.6/dist/esm';

      await ffmpeg.load({
        coreURL: await toBlobURL(
          `${baseURL}/ffmpeg-core.js`,
          'text/javascript',
        ),
        wasmURL: await toBlobURL(
          `${baseURL}/ffmpeg-core.wasm`,
          'application/wasm',
        ),
      });

      setStatusMessage('Processing input file...');
      await ffmpeg.writeFile('input.webm', await fetchFile(recordingBlob));

      setStatusMessage('Starting conversion...');
      await ffmpeg.exec(['-i', 'input.webm', '-c:v', 'libx264', 'output.mp4']);

      setStatusMessage('Reading converted file...');
      const data = await ffmpeg.readFile('output.mp4');

      const url = URL.createObjectURL(
        new Blob([data instanceof Uint8Array ? data : new Uint8Array()], {
          type: 'video/mp4',
        }),
      );
      const link = document.createElement('a');
      link.href = url;
      link.download = 'recording.mp4';
      link.click();
      URL.revokeObjectURL(url);

      setFfmpegStatus('idle');
      setStatusMessage('');
      onClose();
    } catch (error) {
      const errorMessage =
        error instanceof Error ? error.message : String(error);
      console.error('Error converting video:', errorMessage);
      setStatusMessage(`Error: ${errorMessage}`);
      setFfmpegStatus('idle');
    }
  };

  const handleUpload = async () => {
    if (!uploadConfig?.preflightUrl || !recordingBlob) return;

    const metadata = collectMetadata(
      recordingBlob,
      uploadConfig.extraMetadata,
    );

    setUploadState({ status: 'preflighting' });

    try {
      const result = await uploadVideo(recordingBlob, {
        preflightUrl: uploadConfig.preflightUrl,
        metadata,
        onProgress: (p) => setUploadState({ status: 'uploading', progress: p }),
        timeout: uploadConfig.timeout,
      });

      setUploadState({ status: 'success', url: result.url });
      postToParent({ type: 'RECORDER_UPLOADED', url: result.url, id: result.id });
    } catch (err) {
      const message =
        err instanceof UploadError
          ? `${err.message}${err.statusCode ? ` (${err.statusCode})` : ''}`
          : err instanceof Error
            ? err.message
            : String(err);
      setUploadState({ status: 'error', message });
    }
  };

  const copyUrl = () => {
    if (uploadState.status === 'success') {
      navigator.clipboard.writeText(uploadState.url);
    }
  };

  const resetUpload = () => {
    setUploadState({ status: 'idle' });
  };

  const renderUploadSection = () => {
    if (!uploadConfig?.preflightUrl) return null;

    switch (uploadState.status) {
      case 'idle':
        return (
          <Button onClick={handleUpload} className={styles.uploadButton}>
            Upload
          </Button>
        );
      case 'preflighting':
        return (
          <div className={styles.uploadStatus}>
            <p>Preparing upload...</p>
            <div className={styles.spinner} />
          </div>
        );
      case 'uploading':
        return (
          <div className={styles.uploadStatus}>
            <p>Uploading... {uploadState.progress.percent}%</p>
            <div className={styles.progressBar}>
              <div
                className={styles.progressBarFill}
                style={{ width: `${uploadState.progress.percent}%` }}
              />
            </div>
            <p className={styles.uploadSize}>
              {(uploadState.progress.loaded / (1024 * 1024)).toFixed(1)} MB /{' '}
              {(uploadState.progress.total / (1024 * 1024)).toFixed(1)} MB
            </p>
          </div>
        );
      case 'success':
        return (
          <div className={styles.uploadSuccess}>
            <p className={styles.successMessage}>Upload complete!</p>
            <div className={styles.urlRow}>
              <input
                type="text"
                readOnly
                value={uploadState.url}
                className={styles.urlInput}
              />
              <Button onClick={copyUrl} className={styles.copyButton}>
                Copy
              </Button>
            </div>
          </div>
        );
      case 'error':
        return (
          <div className={styles.uploadError}>
            <p className={styles.errorMessage}>{uploadState.message}</p>
            <Button onClick={resetUpload} className={styles.retryButton}>
              Retry
            </Button>
          </div>
        );
    }
  };

  const isBusy =
    ffmpegStatus !== 'idle' ||
    uploadState.status === 'preflighting' ||
    uploadState.status === 'uploading';

  return (
    <div className={styles.overlay}>
      <div className={styles.modal}>
        <h2 className={styles.title}>Recording Complete</h2>
        {ffmpegStatus !== 'idle' ? (
          <div>
            <p className={styles.statusText}>{statusMessage}</p>
            {ffmpegStatus === 'converting' && (
              <div className={styles.progressBar}>
                <div
                  className={styles.progressBarFill}
                  style={{ width: `${progress}%` }}
                />
              </div>
            )}
          </div>
        ) : (
          <>
            <div className={styles.actions}>
              <Button onClick={convertToMp4} className={styles.convertButton}>
                Convert (MP4)
              </Button>
              <Button onClick={downloadWebm} className={styles.downloadButton}>
                Download (WebM)
              </Button>
              {!isBusy && renderUploadSection()}
            </div>
            {uploadState.status === 'success' && (
              <div className={styles.footerActions}>
                <Button onClick={onClose} className={styles.doneButton}>
                  Done
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
};

import CssBaseline from '@mui/material/CssBaseline';
import {
  Experimental_CssVarsProvider as CssVarsProvider,
  StyledEngineProvider,
} from '@mui/material/styles';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import BrowserNotSupported from 'components/BrowserNotSupported';
import Compose from 'components/Compose';
import { CameraShapeProvider } from 'contexts/cameraShape';
import { CountdownProvider } from 'contexts/countdown';
import { LayoutProvider } from 'contexts/layout';
import { MediaDevicesProvider } from 'contexts/mediaDevices';
import { PictureInPictureProvider } from 'contexts/pictureInPicture';
import { RecordingProvider } from 'contexts/recording';
import { ScreenshareProvider } from 'contexts/screenshare';
import { StreamsProvider } from 'contexts/streams';
import { UploadConfigProvider } from 'contexts/uploadConfig';

import App from './App';
import theme from './theme';

const isBrowserSupported =
  'documentPictureInPicture' in window &&
  'MediaStreamTrackProcessor' in window &&
  'MediaStreamTrackGenerator' in window;

const uploadConfig = window.__RECORDER_UPLOAD_CONFIG__;

declare global {
  interface Window {
    __RECORDER_UPLOAD_CONFIG__?: {
      preflightUrl: string;
      extraMetadata?: Record<string, unknown>;
      timeout?: number;
    };
  }
}

createRoot(document.getElementById('root') as HTMLElement).render(
  <StrictMode>
    <StyledEngineProvider injectFirst>
      <CssVarsProvider theme={theme} defaultMode="dark">
        <CssBaseline />
        {isBrowserSupported ? (
          <UploadConfigProvider
            config={uploadConfig ?? { preflightUrl: '' }}
          >
            <Compose
              components={[
                LayoutProvider,
                StreamsProvider,
                RecordingProvider,
                CameraShapeProvider,
                PictureInPictureProvider,
                MediaDevicesProvider,
                ScreenshareProvider,
                CountdownProvider,
              ]}
            >
              <App />
            </Compose>
          </UploadConfigProvider>
        ) : (
          <BrowserNotSupported />
        )}
      </CssVarsProvider>
    </StyledEngineProvider>
  </StrictMode>,
);

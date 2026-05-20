import { useEffect } from 'react';

import { useRecording } from 'contexts/recording';

const useParentMessageListener = () => {
  const { startRecording, stopRecording } = useRecording();

  useEffect(() => {
    const handler = (event: MessageEvent) => {
      const { type } = event.data || {};

      switch (type) {
        case 'RECORDER_START':
          startRecording();
          break;
        case 'RECORDER_STOP':
          stopRecording();
          break;
      }
    };

    window.addEventListener('message', handler);
    return () => window.removeEventListener('message', handler);
  }, [startRecording, stopRecording]);
};

export default useParentMessageListener;

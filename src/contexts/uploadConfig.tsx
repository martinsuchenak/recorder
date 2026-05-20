import { createContext, useContext } from 'react';

type UploadConfigContextType = {
  preflightUrl: string;
  extraMetadata?: Record<string, unknown>;
  timeout?: number;
};

export const UploadConfigContext = createContext<
  UploadConfigContextType | undefined
>(undefined);

type UploadConfigProviderProps = {
  config: UploadConfigContextType;
  children: React.ReactNode;
};

export const UploadConfigProvider = ({
  config,
  children,
}: UploadConfigProviderProps) => (
  <UploadConfigContext.Provider value={config}>
    {children}
  </UploadConfigContext.Provider>
);

export const useUploadConfig = (): UploadConfigContextType | undefined => {
  return useContext(UploadConfigContext);
};

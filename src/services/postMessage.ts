type MessageData = {
  type: string;
  [key: string]: unknown;
};

export const postToParent = (data: MessageData) => {
  if (window.parent !== window) {
    window.parent.postMessage(data, '*');
  }
  if (window.opener) {
    window.opener.postMessage(data, '*');
  }
};

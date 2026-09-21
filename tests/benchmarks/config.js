export const ENDPOINT = (__ENV.APPWRITE_ENDPOINT || 'http://localhost:9520/v1').replace(/\/+$/, '');
export const REALTIME_URL = (__ENV.APPWRITE_REALTIME_URL || `${ENDPOINT.replace(/^http/, 'ws')}/realtime`).replace(/\/+$/, '');

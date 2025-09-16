/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_APPWRITE_ENDPOINT: string
  readonly VITE_APPWRITE_PROJECT_ID: string
  readonly VITE_APPWRITE_OAUTH2_CALLBACK_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
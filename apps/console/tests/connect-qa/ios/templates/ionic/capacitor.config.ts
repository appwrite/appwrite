import type { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'io.appwrite.connectqa',
  appName: 'Connect QA',
  webDir: 'dist',
  server: {
    // Appwrite rejects WebView origins with custom schemes (the default is
    // capacitor://localhost) unless the scheme has this exact form. The
    // Capacitor CLI evaluates this file in Node, so the QA project id can
    // come straight from the workflow environment.
    iosScheme: `appwrite-callback-${process.env.CODE_SNIPPET_QA_PROJECT_ID}`,
  },
}

export default config

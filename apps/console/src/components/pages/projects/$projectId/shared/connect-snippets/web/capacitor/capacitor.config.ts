import type { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'io.appwrite.connectqa',
  appName: 'Connect QA',
  webDir: 'dist',
  server: {
    // Appwrite rejects WebView origins with custom schemes (the default is
    // capacitor://localhost) unless the scheme has this exact form.
    iosScheme: 'appwrite-callback-{{PROJECT_ID}}',
  },
}

export default config

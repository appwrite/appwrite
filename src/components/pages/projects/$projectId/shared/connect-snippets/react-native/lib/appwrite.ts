import { Client } from 'react-native-appwrite'

// Metro only inlines env vars prefixed with EXPO_PUBLIC_; an unprefixed
// process.env read compiles to undefined in the bundle.
const client = new Client()
  .setEndpoint(process.env.EXPO_PUBLIC_APPWRITE_ENDPOINT!)
  .setProject(process.env.EXPO_PUBLIC_APPWRITE_PROJECT_ID!)

export { client }

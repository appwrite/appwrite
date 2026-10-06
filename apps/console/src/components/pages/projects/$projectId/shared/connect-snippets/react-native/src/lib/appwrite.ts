import { Client } from 'react-native-appwrite'

const client = new Client()
  .setEndpoint(process.env.EXPO_PUBLIC_APPWRITE_ENDPOINT!)
  .setProject(process.env.EXPO_PUBLIC_APPWRITE_PROJECT_ID!)
  // The package name or bundle ID of a platform added to this project.
  // Appwrite rejects requests from unregistered apps.
  .setPlatform(process.env.EXPO_PUBLIC_APPWRITE_PLATFORM!)

export { client }

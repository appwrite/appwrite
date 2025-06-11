import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

let storage = Storage(client)

let result = try await storage.deleteFile(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>"
)


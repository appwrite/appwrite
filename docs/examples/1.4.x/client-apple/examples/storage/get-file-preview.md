import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let storage = Storage(client)

let byteBuffer = try await storage.getFilePreview(
    bucketId: "[BUCKET_ID]",
    fileId: "[FILE_ID]"
)


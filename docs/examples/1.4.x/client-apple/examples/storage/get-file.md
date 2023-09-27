import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let storage = Storage(client)

let file = try await storage.getFile(
    bucketId: "[BUCKET_ID]",
    fileId: "[FILE_ID]"
)


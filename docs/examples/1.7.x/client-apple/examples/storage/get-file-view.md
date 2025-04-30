import Appwrite

let client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let storage = Storage(client)

let bytes = try await storage.getFileView(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>"
)


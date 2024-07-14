import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let storage = Storage(client)

let result = try await storage.deleteFile(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>"
)


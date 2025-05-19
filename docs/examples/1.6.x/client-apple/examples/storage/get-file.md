import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let storage = Storage(client)

let file = try await storage.getFile(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>"
)


import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let storage = Storage(client)

let file = try await storage.updateFile(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>",
    name: "<NAME>", // optional
    permissions: ["read("any")"] // optional
)


import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let storage = Storage(client)

let bytes = try await storage.getFilePreview(
    bucketId: "[BUCKET_ID]",
    fileId: "[FILE_ID]",
    width: 0, // optional
    height: 0, // optional
    gravity: .center, // optional
    quality: 0, // optional
    borderWidth: 0, // optional
    borderColor: "", // optional
    borderRadius: 0, // optional
    opacity: 0, // optional
    rotation: -360, // optional
    background: "", // optional
    output: .jpg // optional
)


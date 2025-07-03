import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let tokens = Tokens(client)

let resourceToken = try await tokens.createFileToken(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>",
    expire: "" // optional
)


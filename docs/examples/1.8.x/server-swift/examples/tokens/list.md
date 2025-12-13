import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let tokens = Tokens(client)

let resourceTokenList = try await tokens.list(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>",
    queries: [], // optional
    total: false // optional
)


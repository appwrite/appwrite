import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let tokens = Tokens(client)

let resourceTokenList = try await tokens.list(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>",
    queries: [] // optional
)


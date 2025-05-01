import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let tokens = Tokens(client)

let resourceToken = try await tokens.get(
    tokenId: "<TOKEN_ID>"
)


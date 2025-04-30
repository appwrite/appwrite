import Appwrite

let client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

let tokens = Tokens(client)

let resourceToken = try await tokens.get(
    tokenId: "<TOKEN_ID>"
)


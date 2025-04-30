import Appwrite

let client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let tokens = Tokens(client)

let result = try await tokens.delete(
    tokenId: "<TOKEN_ID>"
)


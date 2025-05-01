import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

let tokens = Tokens(client)

let resourceToken = try await tokens.update(
    tokenId: "<TOKEN_ID>",
    expire: "", // optional
    permissions: ["read("any")"] // optional
)


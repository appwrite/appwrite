import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let tokens = Tokens(client)

let resourceToken = try await tokens.update(
    tokenId: "<TOKEN_ID>",
    expire: "", // optional
    permissions: ["read("any")"] // optional
)


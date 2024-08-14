import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let account = Account(client)

let success = try await account.createOAuth2Token(
    provider: .amazon,
    success: "https://example.com", // optional
    failure: "https://example.com", // optional
    scopes: [] // optional
)


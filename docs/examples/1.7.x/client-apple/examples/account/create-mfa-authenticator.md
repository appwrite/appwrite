import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let account = Account(client)

let mfaType = try await account.createMfaAuthenticator(
    type: .totp
)


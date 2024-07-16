import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let account = Account(client)

let user = try await account.updateMfaAuthenticator(
    type: .totp,
    otp: "<OTP>"
)


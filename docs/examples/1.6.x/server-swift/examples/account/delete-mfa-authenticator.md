import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setSession("") // The user session to authenticate with

let account = Account(client)

let result = try await account.deleteMfaAuthenticator(
    type: .totp,
    otp: "<OTP>"
)


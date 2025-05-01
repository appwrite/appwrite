import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let users = Users(client)

let target = try await users.createTarget(
    userId: "<USER_ID>",
    targetId: "<TARGET_ID>",
    providerType: .email,
    identifier: "<IDENTIFIER>",
    providerId: "<PROVIDER_ID>", // optional
    name: "<NAME>" // optional
)


import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let account = Account(client)

let target = try await account.createPushTarget(
    targetId: "[TARGET_ID]",
    identifier: "[IDENTIFIER]",
    providerId: "[PROVIDER_ID]" // optional
)


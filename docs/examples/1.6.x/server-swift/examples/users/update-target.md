import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

let users = Users(client)

let target = try await users.updateTarget(
    userId: "<USER_ID>",
    targetId: "<TARGET_ID>",
    identifier: "<IDENTIFIER>", // optional
    providerId: "<PROVIDER_ID>", // optional
    name: "<NAME>" // optional
)


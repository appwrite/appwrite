import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let users = Users(client)

let mfaProviders = try await users.listProviders(
    userId: "[USER_ID]"
)


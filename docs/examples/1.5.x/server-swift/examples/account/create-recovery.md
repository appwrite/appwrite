import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setSession("") // The user session to authenticate with

let account = Account(client)

let token = try await account.createRecovery(
    email: "email@example.com",
    url: "https://example.com"
)


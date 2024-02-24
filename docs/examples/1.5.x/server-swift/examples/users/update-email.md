import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

let users = Users(client)

let user = try await users.updateEmail(
    userId: "<USER_ID>",
    email: "email@example.com"
)


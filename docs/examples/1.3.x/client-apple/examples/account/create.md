import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let account = Account(client)

let user = try await account.create(
    userId: "[USER_ID]",
    email: "email@example.com",
    password: ""
)


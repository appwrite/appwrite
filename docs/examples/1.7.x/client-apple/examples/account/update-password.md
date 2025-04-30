import Appwrite

let client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let account = Account(client)

let user = try await account.updatePassword(
    password: "",
    oldPassword: "password" // optional
)


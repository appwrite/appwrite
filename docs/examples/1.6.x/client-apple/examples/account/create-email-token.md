import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let account = Account(client)

let token = try await account.createEmailToken(
    userId: "<USER_ID>",
    email: "email@example.com",
    phrase: false // optional
)


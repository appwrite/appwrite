import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let account = Account(client)

let session = try await account.createSession(
    userId: "<USER_ID>",
    secret: "<SECRET>"
)


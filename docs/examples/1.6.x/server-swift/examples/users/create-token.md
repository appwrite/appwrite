import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

let users = Users(client)

let token = try await users.createToken(
    userId: "<USER_ID>",
    length: 4, // optional
    expire: 60 // optional
)


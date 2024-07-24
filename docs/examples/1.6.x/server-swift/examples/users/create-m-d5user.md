import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

let users = Users(client)

let user = try await users.createMD5User(
    userId: "<USER_ID>",
    email: "email@example.com",
    password: "password",
    name: "<NAME>" // optional
)


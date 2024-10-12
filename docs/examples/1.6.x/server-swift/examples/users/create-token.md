import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let users = Users(client)

let token = try await users.createToken(
    userId: "<USER_ID>",
    length: 4, // optional
    expire: 60 // optional
)


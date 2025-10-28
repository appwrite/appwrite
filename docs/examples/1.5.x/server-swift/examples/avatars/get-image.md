import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

let avatars = Avatars(client)

let bytes = try await avatars.getImage(
    url: "https://example.com",
    width: 0, // optional
    height: 0 // optional
)


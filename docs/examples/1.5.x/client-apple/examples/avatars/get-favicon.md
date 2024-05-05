import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let avatars = Avatars(client)

let bytes = try await avatars.getFavicon(
    url: "https://example.com"
)


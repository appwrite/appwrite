import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let avatars = Avatars(client)

let byteBuffer = try await avatars.getBrowser(
    code: "aa"
)


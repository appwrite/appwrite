import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

let avatars = Avatars(client)

let bytes = try await avatars.getQR(
    text: "<TEXT>",
    size: 1, // optional
    margin: 0, // optional
    download: false // optional
)


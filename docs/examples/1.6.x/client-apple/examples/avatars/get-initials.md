import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let avatars = Avatars(client)

let bytes = try await avatars.getInitials(
    name: "<NAME>", // optional
    width: 0, // optional
    height: 0, // optional
    background: "" // optional
)


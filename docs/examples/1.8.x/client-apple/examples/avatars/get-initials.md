```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let avatars = Avatars(client)

let bytes = try await avatars.getInitials(
    name: "<NAME>", // optional
    width: 0, // optional
    height: 0, // optional
    background: "" // optional
)

```

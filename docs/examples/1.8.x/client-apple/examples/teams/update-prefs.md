```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let teams = Teams(client)

let preferences = try await teams.updatePrefs(
    teamId: "<TEAM_ID>",
    prefs: [:]
)

```

import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let teams = Teams(client)

let preferences = try await teams.updatePrefs(
    teamId: "<TEAM_ID>",
    prefs: [:]
)


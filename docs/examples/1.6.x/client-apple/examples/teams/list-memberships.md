import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let teams = Teams(client)

let membershipList = try await teams.listMemberships(
    teamId: "<TEAM_ID>",
    queries: [], // optional
    search: "<SEARCH>" // optional
)


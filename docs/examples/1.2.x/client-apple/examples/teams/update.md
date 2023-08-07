import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let teams = Teams(client)

let team = try await teams.update(
    teamId: "[TEAM_ID]",
    name: "[NAME]"
)


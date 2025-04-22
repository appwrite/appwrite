import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let teams = Teams(client)

let team = try await teams.create(
    teamId: "<TEAM_ID>",
    name: "<NAME>",
    roles: [] // optional
)


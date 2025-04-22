import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let teams = Teams(client)

let result = try await teams.deleteMembership(
    teamId: "<TEAM_ID>",
    membershipId: "<MEMBERSHIP_ID>"
)


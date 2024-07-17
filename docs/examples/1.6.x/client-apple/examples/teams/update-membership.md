import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let teams = Teams(client)

let membership = try await teams.updateMembership(
    teamId: "<TEAM_ID>",
    membershipId: "<MEMBERSHIP_ID>",
    roles: []
)


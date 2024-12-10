import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

let teams = Teams(client)

let membership = try await teams.updateMembership(
    teamId: "<TEAM_ID>",
    membershipId: "<MEMBERSHIP_ID>",
    roles: []
)


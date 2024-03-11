import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setSession("") // The user session to authenticate with

let teams = Teams(client)

let membershipList = try await teams.listMemberships(
    teamId: "<TEAM_ID>",
    queries: [], // optional
    search: "<SEARCH>" // optional
)


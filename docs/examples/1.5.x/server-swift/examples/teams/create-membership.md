import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setSession("") // The user session to authenticate with

let teams = Teams(client)

let membership = try await teams.createMembership(
    teamId: "<TEAM_ID>",
    roles: [],
    email: "email@example.com", // optional
    userId: "<USER_ID>", // optional
    phone: "+12065550100", // optional
    url: "https://example.com", // optional
    name: "<NAME>" // optional
)


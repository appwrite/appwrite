import Appwrite

func main() async throws {
let client = Client()
.setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
.setProject("5df5acd0d48c2") // Your project ID
.setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
let teams = Teams(client)
let membership = try await teams.createMembership(
teamId: "[TEAM_ID]",
email: "email@example.com",
roles: [],
url: "https://example.com"
)

    print(String(describing: membership))

}

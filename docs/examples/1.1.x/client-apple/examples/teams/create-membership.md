import Appwrite

func main() async throws {
let client = Client()
.setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
.setProject("5df5acd0d48c2") // Your project ID
let teams = Teams(client)
let membership = try await teams.createMembership(
teamId: "[TEAM_ID]",
email: "email@example.com",
roles: [],
url: "https://example.com"
)

    print(String(describing: membership))

}

import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setJWT("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...") // Your secret JSON Web Token
    let teams = Teams(client)
    let membership = try await teams.updateMembershipStatus(
        teamId: "[TEAM_ID]",
        membershipId: "[MEMBERSHIP_ID]",
        userId: "[USER_ID]",
        secret: "[SECRET]"
    )

    print(String(describing: membership)
}

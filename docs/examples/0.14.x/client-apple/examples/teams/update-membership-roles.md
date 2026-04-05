import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let teams = Teams(client)
    let membership = try await teams.updateMembershipRoles(
        teamId: "[TEAM_ID]",
        membershipId: "[MEMBERSHIP_ID]",
        roles: []
    )

    print(String(describing: membership)
}

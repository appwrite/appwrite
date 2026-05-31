import Appwrite

func main() {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID

    let teams = Teams(client)
    teams.createMembership(
        teamId: "[TEAM_ID]",
        email: "email@example.com",
        roles: [],
        url: "https://example.com"
    ) { result in
        switch result {
        case .failure(let error):
            print(error.message)
        case .success(let membership):
            print(String(describing: membership)
        }
    }
}

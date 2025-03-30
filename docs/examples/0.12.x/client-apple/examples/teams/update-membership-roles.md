import Appwrite

func main() {
let client = Client()
.setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
.setProject("5df5acd0d48c2") // Your project ID

    let teams = Teams(client)
    teams.updateMembershipRoles(
        teamId: "[TEAM_ID]",
        membershipId: "[MEMBERSHIP_ID]",
        roles: []
    ) { result in
        switch result {
        case .failure(let error):
            print(error.message)
        case .success(let membership):
            print(String(describing: membership))
        }
    }

}

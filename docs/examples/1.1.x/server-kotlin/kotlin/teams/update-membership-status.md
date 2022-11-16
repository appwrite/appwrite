import io.appwrite.Client
import io.appwrite.services.Teams

val client = Client(context)
    .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setJWT("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...") // Your secret JSON Web Token

val teams = Teams(client)

val response = teams.updateMembershipStatus(
    teamId = "[TEAM_ID]",
    membershipId = "[MEMBERSHIP_ID]",
    userId = "[USER_ID]",
    secret = "[SECRET]"
)

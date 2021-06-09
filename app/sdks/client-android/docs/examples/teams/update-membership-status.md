import io.appwrite.Client
import io.appwrite.services.Teams

val client = Client(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID

val teamsService = Teams(client)
val response = teamsService.updateMembershipStatus("[TEAM_ID]", "[MEMBERSHIP_ID]", "[USER_ID]", "[SECRET]")
val json = response.body?.string()
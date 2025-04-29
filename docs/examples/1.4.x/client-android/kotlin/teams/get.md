import io.appwrite.Client
import io.appwrite.services.Teams

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val teams = Teams(client)

val response = teams.get(
    teamId = "[TEAM_ID]"
)

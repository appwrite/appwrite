import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Teams

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val teams = Teams(client)

val result = teams.listMemberships(
    teamId = "<TEAM_ID>", 
    queries = listOf(), // (optional)
    search = "<SEARCH>", // (optional)
)
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Teams

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

val teams = Teams(client)

val result = teams.updatePrefs(
    teamId = "<TEAM_ID>", 
    prefs = mapOf( "a" to "b" ), 
)
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Teams
import io.appwrite.enums.Roles

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val teams = Teams(client)

val result = teams.updateMembership(
    teamId = "<TEAM_ID>", 
    membershipId = "<MEMBERSHIP_ID>", 
    roles = roles.ADMIN,
)
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Grids

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

val grids = Grids(client)

val response = grids.listRows(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    queries = listOf() // optional
)

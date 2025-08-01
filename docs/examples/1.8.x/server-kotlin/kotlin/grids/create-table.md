import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Grids

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val grids = Grids(client)

val response = grids.createTable(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    name = "<NAME>",
    permissions = listOf("read("any")"), // optional
    rowSecurity = false, // optional
    enabled = false // optional
)

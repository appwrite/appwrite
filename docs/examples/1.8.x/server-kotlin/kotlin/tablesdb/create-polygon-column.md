import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.TablesDB

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val tablesDB = TablesDB(client)

val response = tablesDB.createPolygonColumn(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    key = "",
    required = false,
    default = listOf(listOf(listOf(1, 2), listOf(3, 4), listOf(5, 6), listOf(1, 2))) // optional
)

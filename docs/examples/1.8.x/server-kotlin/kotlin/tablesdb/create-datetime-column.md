import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.TablesDB

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val tablesDB = TablesDB(client)

val response = tablesDB.createDatetimeColumn(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    key = "",
    required = false,
    default = "", // optional
    array = false // optional
)

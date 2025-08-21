import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.TablesDb

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val tablesDB = TablesDb(client)

val result = tablesDB.listRows(
    databaseId = "<DATABASE_ID>", 
    tableId = "<TABLE_ID>", 
    queries = listOf(), // (optional)
)
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.TablesDB

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val tablesDB = TablesDB(client)

val result = tablesDB.createOperations(
    transactionId = "<TRANSACTION_ID>", 
    operations = listOf(mapOf(
        "action" to "create",
        "databaseId" to "<DATABASE_ID>",
        "tableId" to "<TABLE_ID>",
        "rowId" to "<ROW_ID>",
        "data" to mapOf(
            "name" to "Walter O'Brien"
        )
    )), // (optional)
)
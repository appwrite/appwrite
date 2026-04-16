import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.TablesDB

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val tablesDB = TablesDB(client)

val response = tablesDB.createOperations(
    transactionId = "<TRANSACTION_ID>",
    operations = listOf(mapOf(
        "action" to "create",
        "databaseId" to "<DATABASE_ID>",
        "tableId" to "<TABLE_ID>",
        "rowId" to "<ROW_ID>",
        "data" to mapOf(
            "name" to "Walter O'Brien"
        )
    )) // optional
)

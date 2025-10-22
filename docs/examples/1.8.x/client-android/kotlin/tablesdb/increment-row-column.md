import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.TablesDB

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val tablesDB = TablesDB(client)

val result = tablesDB.incrementRowColumn(
    databaseId = "<DATABASE_ID>", 
    tableId = "<TABLE_ID>", 
    rowId = "<ROW_ID>", 
    column = "", 
    value = 0, // (optional)
    max = 0, // (optional)
    transactionId = "<TRANSACTION_ID>", // (optional)
)
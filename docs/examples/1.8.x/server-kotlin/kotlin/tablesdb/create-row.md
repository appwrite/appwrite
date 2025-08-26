import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.TablesDB

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

val tablesDB = TablesDB(client)

val response = tablesDB.createRow(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    rowId = "<ROW_ID>",
    data = mapOf( "a" to "b" ),
    permissions = listOf("read("any")") // optional
)

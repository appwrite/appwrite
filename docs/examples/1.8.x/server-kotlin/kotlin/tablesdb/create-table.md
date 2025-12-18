import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.TablesDB
import io.appwrite.Permission
import io.appwrite.Role

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val tablesDB = TablesDB(client)

val response = tablesDB.createTable(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    name = "<NAME>",
    permissions = listOf(Permission.read(Role.any())), // optional
    rowSecurity = false, // optional
    enabled = false, // optional
    columns = listOf(), // optional
    indexes = listOf() // optional
)

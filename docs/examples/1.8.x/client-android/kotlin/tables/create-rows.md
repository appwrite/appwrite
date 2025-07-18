import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Tables

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setAdmin("") // 
    .setKey("") // 

val tables = Tables(client)

val result = tables.createRows(
    databaseId = "<DATABASE_ID>", 
    tableId = "<TABLE_ID>", 
    rows = listOf(), 
)
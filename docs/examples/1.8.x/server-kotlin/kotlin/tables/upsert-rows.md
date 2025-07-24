import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Tables

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setAdmin("") // 
    .setKey("<YOUR_API_KEY>") // Your secret API key

val tables = Tables(client)

val response = tables.upsertRows(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>"
)

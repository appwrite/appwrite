import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Tables

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setSession("") // The user session to authenticate with
    .setKey("<YOUR_API_KEY>") // Your secret API key
    .setJWT("<YOUR_JWT>") // Your secret JSON Web Token

val tables = Tables(client)

val response = tables.upsertRow(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    rowId = "<ROW_ID>"
)

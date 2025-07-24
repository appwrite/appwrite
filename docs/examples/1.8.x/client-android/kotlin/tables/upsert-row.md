import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Tables

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setSession("") // The user session to authenticate with
    .setKey("") // 
    .setJWT("<YOUR_JWT>") // Your secret JSON Web Token

val tables = Tables(client)

val result = tables.upsertRow(
    databaseId = "<DATABASE_ID>", 
    tableId = "<TABLE_ID>", 
    rowId = "<ROW_ID>", 
)
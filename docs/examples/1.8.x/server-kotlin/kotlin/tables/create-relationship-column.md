import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Tables
import io.appwrite.enums.Type

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val tables = Tables(client)

val response = tables.createRelationshipColumn(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    relatedTableId = "<RELATED_TABLE_ID>",
    type =  .ONETOONE,
    twoWay = false, // optional
    key = "", // optional
    twoWayKey = "", // optional
    onDelete = "cascade" // optional
)

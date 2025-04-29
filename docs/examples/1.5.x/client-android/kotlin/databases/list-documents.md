import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Databases

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val databases = Databases(client)

val result = databases.listDocuments(
    databaseId = "<DATABASE_ID>", 
    collectionId = "<COLLECTION_ID>", 
    queries = listOf(), // (optional)
)
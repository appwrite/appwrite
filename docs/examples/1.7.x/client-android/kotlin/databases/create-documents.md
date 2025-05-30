import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Databases

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setKey("") // 

val databases = Databases(client)

val result = databases.createDocuments(
    databaseId = "<DATABASE_ID>", 
    collectionId = "<COLLECTION_ID>", 
    documents = listOf(), 
)
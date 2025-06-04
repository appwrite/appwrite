import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Databases

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setKey("<YOUR_API_KEY>") // Your secret API key

val databases = Databases(client)

val response = databases.createDocuments(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documents = listOf()
)

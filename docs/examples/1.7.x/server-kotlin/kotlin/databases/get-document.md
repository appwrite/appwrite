import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Databases

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

val databases = Databases(client)

val response = databases.getDocument(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documentId = "<DOCUMENT_ID>",
    queries = listOf() // optional
)

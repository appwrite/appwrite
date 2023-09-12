import io.appwrite.Client
import io.appwrite.services.Databases

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val databases = Databases(client)

val response = databases.createDocument(
    databaseId = "[DATABASE_ID]",
    collectionId = "[COLLECTION_ID]",
    documentId = "[DOCUMENT_ID]",
    data = mapOf( "a" to "b" ),
)

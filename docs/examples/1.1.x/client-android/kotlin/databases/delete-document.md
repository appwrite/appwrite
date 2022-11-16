import io.appwrite.Client
import io.appwrite.services.Databases

val client = Client(context)
    .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val databases = Databases(client)

val response = databases.deleteDocument(
    databaseId = "[DATABASE_ID]",
    collectionId = "[COLLECTION_ID]",
    documentId = "[DOCUMENT_ID]"
)

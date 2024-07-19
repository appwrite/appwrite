import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Databases
import io.appwrite.Permission
import io.appwrite.Role

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setSession("") // The user session to authenticate with

val databases = Databases(client)

val response = databases.updateDocument(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documentId = "<DOCUMENT_ID>",
    data = mapOf( "a" to "b" ), // optional
    permissions = listOf(Permission.read(Role.any())) // optional
)

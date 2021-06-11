import io.appwrite.Client
import io.appwrite.services.Database

val client = Client(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID

val databaseService = Database(client)
val response = databaseService.deleteDocument("[COLLECTION_ID]", "[DOCUMENT_ID]")
val json = response.body?.string()
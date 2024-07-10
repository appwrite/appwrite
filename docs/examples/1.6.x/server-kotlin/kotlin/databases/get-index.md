import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Databases

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

val databases = Databases(client)

val response = databases.getIndex(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    key = ""
)

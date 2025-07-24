import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Databases

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setSession("") // The user session to authenticate with
    .setKey("<YOUR_API_KEY>") // Your secret API key
    .setJWT("<YOUR_JWT>") // Your secret JSON Web Token

val databases = Databases(client)

val response = databases.createDocument(
    databaseId = "<DATABASE_ID>",
    collectionId = "<COLLECTION_ID>",
    documentId = "<DOCUMENT_ID>",
    data = mapOf( "a" to "b" ),
    permissions = listOf("read("any")") // optional
)

import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Databases
import io.appwrite.Permission
import io.appwrite.Role

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val databases = Databases(client)

val result = databases.createDocument(
    databaseId = "<DATABASE_ID>", 
    collectionId = "<COLLECTION_ID>", 
    documentId = "<DOCUMENT_ID>", 
    data = mapOf(
        "username" to "walter.obrien",
        "email" to "walter.obrien@example.com",
        "fullName" to "Walter O'Brien",
        "age" to 30,
        "isAdmin" to false
    ), 
    permissions = listOf(Permission.read(Role.any())), // (optional)
    transactionId = "<TRANSACTION_ID>", // (optional)
)
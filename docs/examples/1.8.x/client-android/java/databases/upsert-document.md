import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;
import io.appwrite.Permission;
import io.appwrite.Role;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>"); // Your project ID

Databases databases = new Databases(client);

databases.upsertDocument(
    "<DATABASE_ID>", // databaseId 
    "<COLLECTION_ID>", // collectionId 
    "<DOCUMENT_ID>", // documentId 
    mapOf( "a" to "b" ), // data 
    listOf(Permission.read(Role.any())), // permissions (optional)
    "<TRANSACTION_ID>", // transactionId (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);


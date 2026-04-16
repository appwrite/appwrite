import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>"); // Your project ID

Databases databases = new Databases(client);

databases.createOperations(
    "<TRANSACTION_ID>", // transactionId 
    List.of(Map.of(
        "action", "create",
        "databaseId", "<DATABASE_ID>",
        "collectionId", "<COLLECTION_ID>",
        "documentId", "<DOCUMENT_ID>",
        "data", Map.of(
            "name", "Walter O'Brien"
        )
    )), // operations (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);


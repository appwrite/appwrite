import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>"); // Your project ID

Databases databases = new Databases(client);

databases.decrementDocumentAttribute(
    "<DATABASE_ID>", // databaseId 
    "<COLLECTION_ID>", // collectionId 
    "<DOCUMENT_ID>", // documentId 
    "", // attribute 
    0, // value (optional)
    0, // min (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);


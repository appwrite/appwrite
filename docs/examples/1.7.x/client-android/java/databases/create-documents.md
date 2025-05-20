import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setKey(""); // 

Databases databases = new Databases(client);

databases.createDocuments(
    "<DATABASE_ID>", // databaseId 
    "<COLLECTION_ID>", // collectionId 
    listOf(), // documents 
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);


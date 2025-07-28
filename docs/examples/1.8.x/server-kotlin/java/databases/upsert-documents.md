import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setAdmin("") // 
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Databases databases = new Databases(client);

databases.upsertDocuments(
    "<DATABASE_ID>", // databaseId
    "<COLLECTION_ID>", // collectionId
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


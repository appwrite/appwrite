import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Databases databases = new Databases(client);

databases.updateRelationshipAttribute(
    "<DATABASE_ID>", // databaseId
    "<COLLECTION_ID>", // collectionId
    "", // key
    RelationMutate.CASCADE, // onDelete (optional)
    "", // newKey (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


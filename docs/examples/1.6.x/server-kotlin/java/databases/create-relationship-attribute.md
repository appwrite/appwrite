import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;
import io.appwrite.enums.RelationshipType;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Databases databases = new Databases(client);

databases.createRelationshipAttribute(
    "<DATABASE_ID>", // databaseId
    "<COLLECTION_ID>", // collectionId
    "<RELATED_COLLECTION_ID>", // relatedCollectionId
    RelationshipType.ONETOONE, // type
    false, // twoWay (optional)
    "", // key (optional)
    "", // twoWayKey (optional)
    RelationMutate.CASCADE, // onDelete (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


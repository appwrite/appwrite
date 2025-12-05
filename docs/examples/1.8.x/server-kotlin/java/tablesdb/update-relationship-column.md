import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.TablesDB;
import io.appwrite.enums.RelationMutate;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

TablesDB tablesDB = new TablesDB(client);

tablesDB.updateRelationshipColumn(
    "<DATABASE_ID>", // databaseId
    "<TABLE_ID>", // tableId
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


import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.TablesDB;
import io.appwrite.enums.IndexType;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

TablesDB tablesDB = new TablesDB(client);

tablesDB.createIndex(
    "<DATABASE_ID>", // databaseId
    "<TABLE_ID>", // tableId
    "", // key
    IndexType.KEY, // type
    List.of(), // columns
    List.of(), // orders (optional)
    List.of(), // lengths (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


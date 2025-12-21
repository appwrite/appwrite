import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.TablesDB;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

TablesDB tablesDB = new TablesDB(client);

tablesDB.createOperations(
    "<TRANSACTION_ID>", // transactionId
    List.of(Map.of(
        "action", "create",
        "databaseId", "<DATABASE_ID>",
        "tableId", "<TABLE_ID>",
        "rowId", "<ROW_ID>",
        "data", Map.of(
            "name", "Walter O'Brien"
        )
    )), // operations (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


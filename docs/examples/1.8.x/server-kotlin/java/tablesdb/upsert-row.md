import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.TablesDb;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession(""); // The user session to authenticate with

TablesDb tablesDb = new TablesDb(client);

tablesDb.upsertRow(
    "<DATABASE_ID>", // databaseId
    "<TABLE_ID>", // tableId
    "<ROW_ID>", // rowId
    mapOf( "a" to "b" ), // data (optional)
    listOf("read("any")"), // permissions (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


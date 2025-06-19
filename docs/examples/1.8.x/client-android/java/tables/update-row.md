import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Tables;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>"); // Your project ID

Tables tables = new Tables(client);

tables.updateRow(
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

        Log.d("Appwrite", result.toString());
    })
);


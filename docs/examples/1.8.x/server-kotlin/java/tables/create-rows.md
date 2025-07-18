import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Tables;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setAdmin("") // 
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Tables tables = new Tables(client);

tables.createRows(
    "<DATABASE_ID>", // databaseId
    "<TABLE_ID>", // tableId
    listOf(), // rows
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


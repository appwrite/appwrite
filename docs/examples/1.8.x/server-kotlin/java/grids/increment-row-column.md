import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Grids;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Grids grids = new Grids(client);

grids.incrementRowColumn(
    "<DATABASE_ID>", // databaseId
    "<TABLE_ID>", // tableId
    "<ROW_ID>", // rowId
    "", // column
    0, // value (optional)
    0, // max (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


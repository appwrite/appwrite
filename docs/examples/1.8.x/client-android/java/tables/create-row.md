import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Tables;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setSession("") // The user session to authenticate with
    .setKey("") // 
    .setJWT("<YOUR_JWT>"); // Your secret JSON Web Token

Tables tables = new Tables(client);

tables.createRow(
    "<DATABASE_ID>", // databaseId 
    "<TABLE_ID>", // tableId 
    "<ROW_ID>", // rowId 
    mapOf( "a" to "b" ), // data 
    listOf("read("any")"), // permissions (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);


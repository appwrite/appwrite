import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.TablesDB;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>"); // Your project ID

TablesDB tablesDB = new TablesDB(client);

tablesDB.getTransaction(
    "<TRANSACTION_ID>", // transactionId 
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);


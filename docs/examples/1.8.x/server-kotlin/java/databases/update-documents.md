import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Databases databases = new Databases(client);

databases.updateDocuments(
    "<DATABASE_ID>", // databaseId
    "<COLLECTION_ID>", // collectionId
    Map.of(
        "username", "walter.obrien",
        "email", "walter.obrien@example.com",
        "fullName", "Walter O'Brien",
        "age", 33,
        "isAdmin", false
    ), // data (optional)
    List.of(), // queries (optional)
    "<TRANSACTION_ID>", // transactionId (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


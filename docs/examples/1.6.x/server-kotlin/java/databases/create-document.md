import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession(""); // The user session to authenticate with

Databases databases = new Databases(client);

databases.createDocument(
    "<DATABASE_ID>", // databaseId
    "<COLLECTION_ID>", // collectionId
    "<DOCUMENT_ID>", // documentId
    mapOf( "a" to "b" ), // data
    listOf("read("any")"), // permissions (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


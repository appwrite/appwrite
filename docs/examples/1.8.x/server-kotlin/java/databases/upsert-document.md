import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setSession("") // The user session to authenticate with
    .setKey("<YOUR_API_KEY>") // Your secret API key
    .setJWT("<YOUR_JWT>"); // Your secret JSON Web Token

Databases databases = new Databases(client);

databases.upsertDocument(
    "<DATABASE_ID>", // databaseId
    "<COLLECTION_ID>", // collectionId
    "<DOCUMENT_ID>", // documentId
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


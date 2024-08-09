import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Databases;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;"); // Your secret API key

Databases databases = new Databases(client);

databases.createUrlAttribute(
    "<DATABASE_ID>", // databaseId
    "<COLLECTION_ID>", // collectionId
    "", // key
    false, // required
    "https://example.com", // default (optional)
    false, // array (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.Permission;
import io.appwrite.Role;
import io.appwrite.services.Databases;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Databases databases = new Databases(client);

databases.createCollection(
    "<DATABASE_ID>", // databaseId
    "<COLLECTION_ID>", // collectionId
    "<NAME>", // name
    List.of(Permission.read(Role.any())), // permissions (optional)
    false, // documentSecurity (optional)
    false, // enabled (optional)
    List.of(), // attributes (optional)
    List.of(), // indexes (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


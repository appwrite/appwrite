import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.Permission;
import io.appwrite.Role;
import io.appwrite.services.Storage;
import io.appwrite.enums.Compression;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Storage storage = new Storage(client);

storage.createBucket(
    "<BUCKET_ID>", // bucketId
    "<NAME>", // name
    List.of(Permission.read(Role.any())), // permissions (optional)
    false, // fileSecurity (optional)
    false, // enabled (optional)
    1, // maximumFileSize (optional)
    List.of(), // allowedFileExtensions (optional)
    Compression.NONE, // compression (optional)
    false, // encryption (optional)
    false, // antivirus (optional)
    false, // transformations (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


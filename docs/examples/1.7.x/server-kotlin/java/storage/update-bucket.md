import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Storage;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Storage storage = new Storage(client);

storage.updateBucket(
    "<BUCKET_ID>", // bucketId
    "<NAME>", // name
    listOf("read("any")"), // permissions (optional)
    false, // fileSecurity (optional)
    false, // enabled (optional)
    1, // maximumFileSize (optional)
    listOf(), // allowedFileExtensions (optional)
    .NONE, // compression (optional)
    false, // encryption (optional)
    false, // antivirus (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


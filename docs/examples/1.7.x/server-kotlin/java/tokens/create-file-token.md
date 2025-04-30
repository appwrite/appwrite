import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Tokens;

Client client = new Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession(""); // The user session to authenticate with

Tokens tokens = new Tokens(client);

tokens.createFileToken(
    "<BUCKET_ID>", // bucketId
    "<FILE_ID>", // fileId
    "", // expire (optional)
    listOf("read("any")"), // permissions (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


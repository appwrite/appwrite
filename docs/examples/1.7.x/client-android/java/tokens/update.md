import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Tokens;

Client client = new Client(context)
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>"); // Your project ID

Tokens tokens = new Tokens(client);

tokens.update(
    "<TOKEN_ID>", // tokenId 
    "", // expire (optional)
    listOf("read("any")"), // permissions (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);


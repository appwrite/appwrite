import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Functions;

Client client = new Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2"); // Your project ID

Functions functions = new Functions(client);

functions.createExecution(
    "<FUNCTION_ID>", // functionId 
    "<BODY>", // body (optional)
    false, // async (optional)
    "<PATH>", // path (optional)
    ExecutionMethod.GET, // method (optional)
    mapOf( "a" to "b" ), // headers (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);


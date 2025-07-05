import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.models.InputFile;
import io.appwrite.services.Storage;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2"); // Your project ID

Storage storage = new Storage(client);

storage.createFile(
    "[BUCKET_ID]",
    "[FILE_ID]",
    InputFile.fromPath("file.png"),
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);

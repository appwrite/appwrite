import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Storage;

Client client = new Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2"); // Your project ID

Storage storage = new Storage(client);

storage.getFilePreview(
    "<BUCKET_ID>", // bucketId 
    "<FILE_ID>", // fileId 
    0, // width (optional)
    0, // height (optional)
    ImageGravity.CENTER, // gravity (optional)
    0, // quality (optional)
    0, // borderWidth (optional)
    "", // borderColor (optional)
    0, // borderRadius (optional)
    0, // opacity (optional)
    -360, // rotation (optional)
    "", // background (optional)
    ImageFormat.JPG, // output (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);


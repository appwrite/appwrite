import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Storage

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val storage = Storage(client)

val result = storage.getFileView(
    bucketId = "<BUCKET_ID>", 
    fileId = "<FILE_ID>", 
)
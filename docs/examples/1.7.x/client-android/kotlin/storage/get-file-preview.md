import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Storage

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val storage = Storage(client)

val result = storage.getFilePreview(
    bucketId = "<BUCKET_ID>", 
    fileId = "<FILE_ID>", 
    width = 0, // (optional)
    height = 0, // (optional)
    gravity = ImageGravity.CENTER, // (optional)
    quality = -1, // (optional)
    borderWidth = 0, // (optional)
    borderColor = "", // (optional)
    borderRadius = 0, // (optional)
    opacity = 0, // (optional)
    rotation = -360, // (optional)
    background = "", // (optional)
    output = ImageFormat.JPG, // (optional)
    token = "<TOKEN>", // (optional)
)
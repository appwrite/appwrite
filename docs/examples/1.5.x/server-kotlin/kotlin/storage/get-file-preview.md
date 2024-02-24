import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Storage

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setSession("") // The user session to authenticate with

val storage = Storage(client)

val result = storage.getFilePreview(
    bucketId = "<BUCKET_ID>",
    fileId = "<FILE_ID>",
    width = 0, // optional
    height = 0, // optional
    gravity = "center", // optional
    quality = 0, // optional
    borderWidth = 0, // optional
    borderColor = "", // optional
    borderRadius = 0, // optional
    opacity = 0, // optional
    rotation = -360, // optional
    background = "", // optional
    output = "jpg" // optional
)

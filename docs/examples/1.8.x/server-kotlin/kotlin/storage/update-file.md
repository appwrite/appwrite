import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Storage
import io.appwrite.Permission
import io.appwrite.Role

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

val storage = Storage(client)

val response = storage.updateFile(
    bucketId = "<BUCKET_ID>",
    fileId = "<FILE_ID>",
    name = "<NAME>", // optional
    permissions = listOf(Permission.read(Role.any())) // optional
)

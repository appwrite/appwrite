import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.models.InputFile
import io.appwrite.services.Storage

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setSession("") // The user session to authenticate with

val storage = Storage(client)

val response = storage.createFile(
    bucketId = "<BUCKET_ID>",
    fileId = "<FILE_ID>",
    file = InputFile.fromPath("file.png"),
    permissions = listOf("read("any")") // optional
)

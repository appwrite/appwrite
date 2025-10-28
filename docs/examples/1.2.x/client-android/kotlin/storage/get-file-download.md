import io.appwrite.Client
import io.appwrite.services.Storage

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val storage = Storage(client)

val result = storage.getFileDownload(
    bucketId = "[BUCKET_ID]",
    fileId = "[FILE_ID]"
)

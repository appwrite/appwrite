import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Health
import io.appwrite.enums.Name

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val health = Health(client)

val response = health.getFailedJobs(
    name =  .V1_DATABASE,
    threshold = 0 // optional
)

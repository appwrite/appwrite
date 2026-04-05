import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Health
import io.appwrite.enums.Name

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val health = Health(client)

val response = health.getFailedJobs(
    name =  .V1_DATABASE,
    threshold = 0 // optional
)

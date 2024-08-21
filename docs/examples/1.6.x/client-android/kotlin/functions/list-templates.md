import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val functions = Functions(client)

val result = functions.listTemplates(
    runtimes = listOf(), // (optional)
    useCases = listOf(), // (optional)
    limit = 1, // (optional)
    offset = 0, // (optional)
)
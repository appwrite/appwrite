import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val functions = Functions(client)

val result = functions.createExecution(
    functionId = "<FUNCTION_ID>", 
    body = "<BODY>", // (optional)
    async = false, // (optional)
    path = "<PATH>", // (optional)
    method = ExecutionMethod.GET, // (optional)
    headers = mapOf( "a" to "b" ), // (optional)
    scheduledAt = "", // (optional)
)
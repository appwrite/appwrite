import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val functions = Functions(client)

val result = functions.getExecution(
    functionId = "<FUNCTION_ID>", 
    executionId = "<EXECUTION_ID>", 
)
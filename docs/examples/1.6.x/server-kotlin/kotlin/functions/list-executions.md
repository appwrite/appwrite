import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

val functions = Functions(client)

val response = functions.listExecutions(
    functionId = "<FUNCTION_ID>",
    queries = listOf(), // optional
    search = "<SEARCH>" // optional
)

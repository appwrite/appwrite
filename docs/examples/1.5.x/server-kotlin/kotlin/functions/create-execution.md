import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setSession("") // The user session to authenticate with

val functions = Functions(client)

val response = functions.createExecution(
    functionId = "<FUNCTION_ID>",
    body = "<BODY>", // optional
    async = false, // optional
    path = "<PATH>", // optional
    method = "GET", // optional
    headers = mapOf( "a" to "b" ) // optional
)

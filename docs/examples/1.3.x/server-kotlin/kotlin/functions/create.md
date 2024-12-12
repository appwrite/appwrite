import io.appwrite.Client
import io.appwrite.services.Functions

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val functions = Functions(client)

val response = functions.create(
    functionId = "[FUNCTION_ID]",
    name = "[NAME]",
    runtime = "node-14.5",
)

import io.appwrite.Client
import io.appwrite.services.Functions

val client = Client(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID

val functionsService = Functions(client)
val response = functionsService.getExecution("[FUNCTION_ID]", "[EXECUTION_ID]")
val json = response.body?.string()
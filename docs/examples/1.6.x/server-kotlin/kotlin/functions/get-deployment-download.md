import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setSession("") // The user session to authenticate with

val functions = Functions(client)

val result = functions.getDeploymentDownload(
    functionId = "<FUNCTION_ID>",
    deploymentId = "<DEPLOYMENT_ID>"
)

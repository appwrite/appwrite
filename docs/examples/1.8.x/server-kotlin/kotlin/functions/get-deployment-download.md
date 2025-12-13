import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions
import io.appwrite.enums.DeploymentDownloadType

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val functions = Functions(client)

val result = functions.getDeploymentDownload(
    functionId = "<FUNCTION_ID>",
    deploymentId = "<DEPLOYMENT_ID>",
    type = "source" // optional
)

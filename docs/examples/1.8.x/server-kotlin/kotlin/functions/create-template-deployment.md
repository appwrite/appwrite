import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions
import io.appwrite.enums.TemplateReferenceType

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val functions = Functions(client)

val response = functions.createTemplateDeployment(
    functionId = "<FUNCTION_ID>",
    repository = "<REPOSITORY>",
    owner = "<OWNER>",
    rootDirectory = "<ROOT_DIRECTORY>",
    type =  TemplateReferenceType.COMMIT,
    reference = "<REFERENCE>",
    activate = false // optional
)

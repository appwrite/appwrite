import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Sites
import io.appwrite.enums.TemplateReferenceType

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val sites = Sites(client)

val response = sites.createTemplateDeployment(
    siteId = "<SITE_ID>",
    repository = "<REPOSITORY>",
    owner = "<OWNER>",
    rootDirectory = "<ROOT_DIRECTORY>",
    type =  TemplateReferenceType.BRANCH,
    reference = "<REFERENCE>",
    activate = false // optional
)

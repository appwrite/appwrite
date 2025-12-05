import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Sites
import io.appwrite.enums.VCSReferenceType

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val sites = Sites(client)

val response = sites.createVcsDeployment(
    siteId = "<SITE_ID>",
    type =  VCSReferenceType.BRANCH,
    reference = "<REFERENCE>",
    activate = false // optional
)

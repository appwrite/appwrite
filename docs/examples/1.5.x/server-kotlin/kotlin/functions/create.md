import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions
import io.appwrite.enums.Runtime

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val functions = Functions(client)

val response = functions.create(
    functionId = "<FUNCTION_ID>",
    name = "<NAME>",
    runtime =  .NODE_14_5,
    execute = listOf("any"), // optional
    events = listOf(), // optional
    schedule = "", // optional
    timeout = 1, // optional
    enabled = false, // optional
    logging = false, // optional
    entrypoint = "<ENTRYPOINT>", // optional
    commands = "<COMMANDS>", // optional
    installationId = "<INSTALLATION_ID>", // optional
    providerRepositoryId = "<PROVIDER_REPOSITORY_ID>", // optional
    providerBranch = "<PROVIDER_BRANCH>", // optional
    providerSilentMode = false, // optional
    providerRootDirectory = "<PROVIDER_ROOT_DIRECTORY>", // optional
    templateRepository = "<TEMPLATE_REPOSITORY>", // optional
    templateOwner = "<TEMPLATE_OWNER>", // optional
    templateRootDirectory = "<TEMPLATE_ROOT_DIRECTORY>", // optional
    templateBranch = "<TEMPLATE_BRANCH>" // optional
)

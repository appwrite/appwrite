import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val functions = Functions(client)

val response = functions.update(
    functionId = "<FUNCTION_ID>",
    name = "<NAME>",
    runtime = "node-14.5", // optional
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
    providerRootDirectory = "<PROVIDER_ROOT_DIRECTORY>" // optional
)

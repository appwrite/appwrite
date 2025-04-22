import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Functions;
import io.appwrite.enums.Runtime;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Functions functions = new Functions(client);

functions.create(
    "<FUNCTION_ID>", // functionId
    "<NAME>", // name
    .NODE_14_5, // runtime
    listOf("any"), // execute (optional)
    listOf(), // events (optional)
    "", // schedule (optional)
    1, // timeout (optional)
    false, // enabled (optional)
    false, // logging (optional)
    "<ENTRYPOINT>", // entrypoint (optional)
    "<COMMANDS>", // commands (optional)
    listOf(), // scopes (optional)
    "<INSTALLATION_ID>", // installationId (optional)
    "<PROVIDER_REPOSITORY_ID>", // providerRepositoryId (optional)
    "<PROVIDER_BRANCH>", // providerBranch (optional)
    false, // providerSilentMode (optional)
    "<PROVIDER_ROOT_DIRECTORY>", // providerRootDirectory (optional)
    "<TEMPLATE_REPOSITORY>", // templateRepository (optional)
    "<TEMPLATE_OWNER>", // templateOwner (optional)
    "<TEMPLATE_ROOT_DIRECTORY>", // templateRootDirectory (optional)
    "<TEMPLATE_VERSION>", // templateVersion (optional)
    "", // specification (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


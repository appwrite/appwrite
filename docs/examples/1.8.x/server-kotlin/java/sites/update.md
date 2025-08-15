import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Sites;
import io.appwrite.enums.Framework;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Sites sites = new Sites(client);

sites.update(
    "<SITE_ID>", // siteId
    "<NAME>", // name
    .ANALOG, // framework
    false, // enabled (optional)
    false, // logging (optional)
    1, // timeout (optional)
    "<INSTALL_COMMAND>", // installCommand (optional)
    "<BUILD_COMMAND>", // buildCommand (optional)
    "<OUTPUT_DIRECTORY>", // outputDirectory (optional)
    .NODE_14_5, // buildRuntime (optional)
    .STATIC, // adapter (optional)
    "<FALLBACK_FILE>", // fallbackFile (optional)
    "<INSTALLATION_ID>", // installationId (optional)
    "<PROVIDER_REPOSITORY_ID>", // providerRepositoryId (optional)
    "<PROVIDER_BRANCH>", // providerBranch (optional)
    false, // providerSilentMode (optional)
    "<PROVIDER_ROOT_DIRECTORY>", // providerRootDirectory (optional)
    "", // specification (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);


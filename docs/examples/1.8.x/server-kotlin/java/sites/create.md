import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Sites;
import io.appwrite.enums.Framework;
import io.appwrite.enums.BuildRuntime;
import io.appwrite.enums.Adapter;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Sites sites = new Sites(client);

sites.create(
    "<SITE_ID>", // siteId
    "<NAME>", // name
    Framework.ANALOG, // framework
    BuildRuntime.NODE_14_5, // buildRuntime
    false, // enabled (optional)
    false, // logging (optional)
    1, // timeout (optional)
    "<INSTALL_COMMAND>", // installCommand (optional)
    "<BUILD_COMMAND>", // buildCommand (optional)
    "<OUTPUT_DIRECTORY>", // outputDirectory (optional)
    Adapter.STATIC, // adapter (optional)
    "<INSTALLATION_ID>", // installationId (optional)
    "<FALLBACK_FILE>", // fallbackFile (optional)
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


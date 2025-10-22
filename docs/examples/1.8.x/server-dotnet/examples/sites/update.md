using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Sites sites = new Sites(client);

Site result = await sites.Update(
    siteId: "<SITE_ID>",
    name: "<NAME>",
    framework: .Analog,
    enabled: false, // optional
    logging: false, // optional
    timeout: 1, // optional
    installCommand: "<INSTALL_COMMAND>", // optional
    buildCommand: "<BUILD_COMMAND>", // optional
    outputDirectory: "<OUTPUT_DIRECTORY>", // optional
    buildRuntime: .Node145, // optional
    adapter: .Static, // optional
    fallbackFile: "<FALLBACK_FILE>", // optional
    installationId: "<INSTALLATION_ID>", // optional
    providerRepositoryId: "<PROVIDER_REPOSITORY_ID>", // optional
    providerBranch: "<PROVIDER_BRANCH>", // optional
    providerSilentMode: false, // optional
    providerRootDirectory: "<PROVIDER_ROOT_DIRECTORY>", // optional
    specification: "" // optional
);
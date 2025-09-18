const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const sites = new sdk.Sites(client);

const result = await sites.update(
    '<SITE_ID>', // siteId
    '<NAME>', // name
    sdk..Analog, // framework
    false, // enabled (optional)
    false, // logging (optional)
    1, // timeout (optional)
    '<INSTALL_COMMAND>', // installCommand (optional)
    '<BUILD_COMMAND>', // buildCommand (optional)
    '<OUTPUT_DIRECTORY>', // outputDirectory (optional)
    sdk..Node145, // buildRuntime (optional)
    sdk..Static, // adapter (optional)
    '<FALLBACK_FILE>', // fallbackFile (optional)
    '<INSTALLATION_ID>', // installationId (optional)
    '<PROVIDER_REPOSITORY_ID>', // providerRepositoryId (optional)
    '<PROVIDER_BRANCH>', // providerBranch (optional)
    false, // providerSilentMode (optional)
    '<PROVIDER_ROOT_DIRECTORY>', // providerRootDirectory (optional)
    '' // specification (optional)
);

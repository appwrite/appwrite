const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const sites = new sdk.Sites(client);

const result = await sites.create({
    siteId: '<SITE_ID>',
    name: '<NAME>',
    framework: sdk..Analog,
    buildRuntime: sdk..Node145,
    enabled: false, // optional
    logging: false, // optional
    timeout: 1, // optional
    installCommand: '<INSTALL_COMMAND>', // optional
    buildCommand: '<BUILD_COMMAND>', // optional
    outputDirectory: '<OUTPUT_DIRECTORY>', // optional
    adapter: sdk..Static, // optional
    installationId: '<INSTALLATION_ID>', // optional
    fallbackFile: '<FALLBACK_FILE>', // optional
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>', // optional
    providerBranch: '<PROVIDER_BRANCH>', // optional
    providerSilentMode: false, // optional
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>', // optional
    specification: '' // optional
});

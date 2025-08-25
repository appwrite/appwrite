const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const sites = new sdk.Sites(client);

const result = await sites.update({
    siteId: '<SITE_ID>',
    name: '<NAME>',
    framework: sdk..Analog,
    enabled: false,
    logging: false,
    timeout: 1,
    installCommand: '<INSTALL_COMMAND>',
    buildCommand: '<BUILD_COMMAND>',
    outputDirectory: '<OUTPUT_DIRECTORY>',
    buildRuntime: sdk..Node145,
    adapter: sdk..Static,
    fallbackFile: '<FALLBACK_FILE>',
    installationId: '<INSTALLATION_ID>',
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>',
    providerBranch: '<PROVIDER_BRANCH>',
    providerSilentMode: false,
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>',
    specification: ''
});

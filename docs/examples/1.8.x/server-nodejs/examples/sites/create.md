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
    enabled: false,
    logging: false,
    timeout: 1,
    installCommand: '<INSTALL_COMMAND>',
    buildCommand: '<BUILD_COMMAND>',
    outputDirectory: '<OUTPUT_DIRECTORY>',
    adapter: sdk..Static,
    installationId: '<INSTALLATION_ID>',
    fallbackFile: '<FALLBACK_FILE>',
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>',
    providerBranch: '<PROVIDER_BRANCH>',
    providerSilentMode: false,
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>',
    specification: ''
});

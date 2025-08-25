const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const functions = new sdk.Functions(client);

const result = await functions.update({
    functionId: '<FUNCTION_ID>',
    name: '<NAME>',
    runtime: sdk..Node145,
    execute: ["any"],
    events: [],
    schedule: '',
    timeout: 1,
    enabled: false,
    logging: false,
    entrypoint: '<ENTRYPOINT>',
    commands: '<COMMANDS>',
    scopes: [],
    installationId: '<INSTALLATION_ID>',
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>',
    providerBranch: '<PROVIDER_BRANCH>',
    providerSilentMode: false,
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>',
    specification: ''
});

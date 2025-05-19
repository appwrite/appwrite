const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const functions = new sdk.Functions(client);

const result = await functions.create(
    '<FUNCTION_ID>', // functionId
    '<NAME>', // name
    sdk..Node145, // runtime
    ["any"], // execute (optional)
    [], // events (optional)
    '', // schedule (optional)
    1, // timeout (optional)
    false, // enabled (optional)
    false, // logging (optional)
    '<ENTRYPOINT>', // entrypoint (optional)
    '<COMMANDS>', // commands (optional)
    [], // scopes (optional)
    '<INSTALLATION_ID>', // installationId (optional)
    '<PROVIDER_REPOSITORY_ID>', // providerRepositoryId (optional)
    '<PROVIDER_BRANCH>', // providerBranch (optional)
    false, // providerSilentMode (optional)
    '<PROVIDER_ROOT_DIRECTORY>', // providerRootDirectory (optional)
    '<TEMPLATE_REPOSITORY>', // templateRepository (optional)
    '<TEMPLATE_OWNER>', // templateOwner (optional)
    '<TEMPLATE_ROOT_DIRECTORY>', // templateRootDirectory (optional)
    '<TEMPLATE_VERSION>', // templateVersion (optional)
    '' // specification (optional)
);

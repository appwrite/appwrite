const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

const functions = new sdk.Functions(client);

const result = await functions.update(
    '<FUNCTION_ID>', // functionId
    '<NAME>', // name
    sdk..Node145, // runtime (optional)
    ["any"], // execute (optional)
    [], // events (optional)
    '', // schedule (optional)
    1, // timeout (optional)
    false, // enabled (optional)
    false, // logging (optional)
    '<ENTRYPOINT>', // entrypoint (optional)
    '<COMMANDS>', // commands (optional)
    '<INSTALLATION_ID>', // installationId (optional)
    '<PROVIDER_REPOSITORY_ID>', // providerRepositoryId (optional)
    '<PROVIDER_BRANCH>', // providerBranch (optional)
    false, // providerSilentMode (optional)
    '<PROVIDER_ROOT_DIRECTORY>' // providerRootDirectory (optional)
);

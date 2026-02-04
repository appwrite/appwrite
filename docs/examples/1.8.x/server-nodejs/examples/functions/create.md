```javascript
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const functions = new sdk.Functions(client);

const result = await functions.create({
    functionId: '<FUNCTION_ID>',
    name: '<NAME>',
    runtime: sdk.Runtime.Node145,
    execute: ["any"], // optional
    events: [], // optional
    schedule: '', // optional
    timeout: 1, // optional
    enabled: false, // optional
    logging: false, // optional
    entrypoint: '<ENTRYPOINT>', // optional
    commands: '<COMMANDS>', // optional
    scopes: [sdk.Scopes.SessionsWrite], // optional
    installationId: '<INSTALLATION_ID>', // optional
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>', // optional
    providerBranch: '<PROVIDER_BRANCH>', // optional
    providerSilentMode: false, // optional
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>', // optional
    specification: '' // optional
});
```

const sdk = require('node-appwrite');
const fs = require('fs');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const sites = new sdk.Sites(client);

const result = await sites.createDeployment({
    siteId: '<SITE_ID>',
    code: InputFile.fromPath('/path/to/file', 'filename'),
    activate: false,
    installCommand: '<INSTALL_COMMAND>',
    buildCommand: '<BUILD_COMMAND>',
    outputDirectory: '<OUTPUT_DIRECTORY>'
});

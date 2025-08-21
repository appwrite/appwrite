import { Client, Sites } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.createDeployment({
    siteId: '<SITE_ID>',
    code: document.getElementById('uploader').files[0],
    activate: false,
    installCommand: '<INSTALL_COMMAND>',
    buildCommand: '<BUILD_COMMAND>',
    outputDirectory: '<OUTPUT_DIRECTORY>'
});

console.log(result);

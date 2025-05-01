import { Client, Sites } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const sites = new Sites(client);

const response = await sites.createDeployment(
    '<SITE_ID>', // siteId
    InputFile.fromPath('/path/to/file.png', 'file.png'), // code
    false, // activate
    '<INSTALL_COMMAND>', // installCommand (optional)
    '<BUILD_COMMAND>', // buildCommand (optional)
    '<OUTPUT_DIRECTORY>' // outputDirectory (optional)
);

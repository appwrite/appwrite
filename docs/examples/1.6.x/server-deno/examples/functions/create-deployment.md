import { Client, Functions } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

const functions = new Functions(client);

const response = await functions.createDeployment(
    '<FUNCTION_ID>', // functionId
    InputFile.fromPath('/path/to/file.png', 'file.png'), // code
    false, // activate
    '<ENTRYPOINT>', // entrypoint (optional)
    '<COMMANDS>' // commands (optional)
);

import { Client, Functions } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const functions = new Functions(client);

const response = await functions.createDeployment({
    functionId: '<FUNCTION_ID>',
    code: InputFile.fromPath('/path/to/file.png', 'file.png'),
    activate: false,
    entrypoint: '<ENTRYPOINT>', // optional
    commands: '<COMMANDS>' // optional
});

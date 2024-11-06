import { Client, Functions } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.createDeployment(
    '<FUNCTION_ID>', // functionId
    document.getElementById('uploader').files[0], // code
    false, // activate
    '<ENTRYPOINT>', // entrypoint (optional)
    '<COMMANDS>' // commands (optional)
);

console.log(result);

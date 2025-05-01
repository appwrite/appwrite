import { Client, Assistant } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const assistant = new Assistant(client);

const result = await assistant.chat(
    '<PROMPT>' // prompt
);

console.log(result);

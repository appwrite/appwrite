import { Client, Assistant } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const assistant = new Assistant(client);

const result = await assistant.chat(
    '<PROMPT>' // prompt
);

console.log(response);

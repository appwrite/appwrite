import { Client, Account } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const account = new Account(client);

const result = await account.createPushTarget(
    '<TARGET_ID>', // targetId
    '<IDENTIFIER>', // identifier
    '<PROVIDER_ID>' // providerId (optional)
);

console.log(response);

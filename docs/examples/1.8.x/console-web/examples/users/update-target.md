import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.updateTarget({
    userId: '<USER_ID>',
    targetId: '<TARGET_ID>',
    identifier: '<IDENTIFIER>',
    providerId: '<PROVIDER_ID>',
    name: '<NAME>'
});

console.log(result);

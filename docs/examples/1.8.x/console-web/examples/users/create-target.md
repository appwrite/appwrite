import { Client, Users, MessagingProviderType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.createTarget({
    userId: '<USER_ID>',
    targetId: '<TARGET_ID>',
    providerType: MessagingProviderType.Email,
    identifier: '<IDENTIFIER>',
    providerId: '<PROVIDER_ID>', // optional
    name: '<NAME>' // optional
});

console.log(result);

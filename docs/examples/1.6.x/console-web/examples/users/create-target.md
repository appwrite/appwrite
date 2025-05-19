import { Client, Users, MessagingProviderType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.createTarget(
    '<USER_ID>', // userId
    '<TARGET_ID>', // targetId
    MessagingProviderType.Email, // providerType
    '<IDENTIFIER>', // identifier
    '<PROVIDER_ID>', // providerId (optional)
    '<NAME>' // name (optional)
);

console.log(result);

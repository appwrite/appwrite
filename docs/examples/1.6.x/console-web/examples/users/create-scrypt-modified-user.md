import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.createScryptModifiedUser(
    '<USER_ID>', // userId
    'email@example.com', // email
    'password', // password
    '<PASSWORD_SALT>', // passwordSalt
    '<PASSWORD_SALT_SEPARATOR>', // passwordSaltSeparator
    '<PASSWORD_SIGNER_KEY>', // passwordSignerKey
    '<NAME>' // name (optional)
);

console.log(result);

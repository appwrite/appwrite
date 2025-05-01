import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.createScryptUser(
    '<USER_ID>', // userId
    'email@example.com', // email
    'password', // password
    '<PASSWORD_SALT>', // passwordSalt
    null, // passwordCpu
    null, // passwordMemory
    null, // passwordParallel
    null, // passwordLength
    '<NAME>' // name (optional)
);

console.log(result);

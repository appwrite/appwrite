const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const users = new sdk.Users(client);

const result = await users.createScryptModifiedUser(
    '<USER_ID>', // userId
    'email@example.com', // email
    'password', // password
    '<PASSWORD_SALT>', // passwordSalt
    '<PASSWORD_SALT_SEPARATOR>', // passwordSaltSeparator
    '<PASSWORD_SIGNER_KEY>', // passwordSignerKey
    '<NAME>' // name (optional)
);

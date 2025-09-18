const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const avatars = new sdk.Avatars(client);

const result = await avatars.getCreditCard(
    sdk.CreditCard.AmericanExpress, // code
    0, // width (optional)
    0, // height (optional)
    0 // quality (optional)
);

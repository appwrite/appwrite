import { Client, Account, OAuthProvider } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new Account(client);

account.createOAuth2Token({
    provider: OAuthProvider.Amazon,
    success: 'https://example.com', // optional
    failure: 'https://example.com', // optional
    scopes: [] // optional
});


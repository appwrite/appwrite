import { Client, Account, OAuthProvider } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new Account(client);

account.createOAuth2Token({
    provider: OAuthProvider.Amazon,
    success: 'https://example.com',
    failure: 'https://example.com',
    scopes: []
});

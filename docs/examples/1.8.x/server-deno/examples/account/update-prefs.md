import { Client, Account } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const account = new Account(client);

const response = await account.updatePrefs({
    prefs: {
        "language": "en",
        "timezone": "UTC",
        "darkTheme": true
    }
});

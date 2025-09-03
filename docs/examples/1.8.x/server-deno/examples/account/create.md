import { Client, Account } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new Account(client);

const response = await account.create({
    userId: '<USER_ID>',
    email: 'email@example.com',
    password: '',
    name: '<NAME>' // optional
});

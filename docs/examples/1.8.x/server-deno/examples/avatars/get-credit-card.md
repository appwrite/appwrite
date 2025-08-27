import { Client, Avatars, CreditCard } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const avatars = new Avatars(client);

const result = avatars.getCreditCard({
    code: CreditCard.AmericanExpress,
    width: 0, // optional
    height: 0, // optional
    quality: -1 // optional
});

import { Client, Avatars, Browser } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const avatars = new Avatars(client);

const result = avatars.getBrowser(
    Browser.AvantBrowser, // code
    0, // width (optional)
    0, // height (optional)
    0 // quality (optional)
);

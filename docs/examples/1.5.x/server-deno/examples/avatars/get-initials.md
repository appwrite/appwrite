import { Client, Avatars } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const avatars = new Avatars(client);

const result = avatars.getInitials(
    '<NAME>', // name (optional)
    0, // width (optional)
    0, // height (optional)
    '' // background (optional)
);

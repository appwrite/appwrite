import { Client, Databases } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const databases = new Databases(client);

const response = await databases.updateCollection(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '<NAME>', // name
    ["read("any")"], // permissions (optional)
    false, // documentSecurity (optional)
    false // enabled (optional)
);

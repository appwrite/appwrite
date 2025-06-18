import { Client, Databases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.createCollection(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '<NAME>', // name
    ["read("any")"], // permissions (optional)
    false, // documentSecurity (optional)
    false // enabled (optional)
);

console.log(result);

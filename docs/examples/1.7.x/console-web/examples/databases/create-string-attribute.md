import { Client, Databases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.createStringAttribute(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '', // key
    1, // size
    false, // required
    '<DEFAULT>', // default (optional)
    false, // array (optional)
    false // encrypt (optional)
);

console.log(result);

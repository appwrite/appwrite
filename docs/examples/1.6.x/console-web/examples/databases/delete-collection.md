import { Client, Databases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const databases = new Databases(client);

const result = await databases.deleteCollection(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>' // collectionId
);

console.log(result);

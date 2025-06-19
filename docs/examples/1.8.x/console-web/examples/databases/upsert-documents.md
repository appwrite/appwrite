import { Client, Databases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setAdmin('') // 
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const databases = new Databases(client);

const result = await databases.upsertDocuments(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>' // collectionId
);

console.log(result);

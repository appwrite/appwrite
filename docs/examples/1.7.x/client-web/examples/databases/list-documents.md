import { Client, Databases } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.listDocuments(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    [] // queries (optional)
);

console.log(result);

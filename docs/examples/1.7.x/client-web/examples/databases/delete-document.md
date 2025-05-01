import { Client, Databases } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.deleteDocument(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '<DOCUMENT_ID>' // documentId
);

console.log(result);

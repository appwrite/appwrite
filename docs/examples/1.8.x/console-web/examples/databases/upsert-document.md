import { Client, Databases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setSession('') // 
    .setKey('<YOUR_API_KEY>') // Your secret API key
    .setJWT('<YOUR_JWT>'); // Your secret JSON Web Token

const databases = new Databases(client);

const result = await databases.upsertDocument(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '<DOCUMENT_ID>' // documentId
);

console.log(result);

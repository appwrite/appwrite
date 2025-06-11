import { Client, Databases } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setSession('') // The user session to authenticate with
    .setKey('') // 
    .setJWT('<YOUR_JWT>'); // Your secret JSON Web Token

const databases = new Databases(client);

const result = await databases.createDocument(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '<DOCUMENT_ID>', // documentId
    {}, // data
    ["read("any")"] // permissions (optional)
);

console.log(result);

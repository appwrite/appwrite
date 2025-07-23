const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setAdmin('') // 
    .setSession('') // The user session to authenticate with
    .setKey('<YOUR_API_KEY>') // Your secret API key
    .setJWT('<YOUR_JWT>'); // Your secret JSON Web Token

const databases = new sdk.Databases(client);

const result = await databases.createDocument(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '<DOCUMENT_ID>', // documentId
    {}, // data
    ["read("any")"] // permissions (optional)
);

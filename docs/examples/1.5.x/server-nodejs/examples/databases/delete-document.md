const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession(''); // The user session to authenticate with

const databases = new sdk.Databases(client);

const result = await databases.deleteDocument(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '<DOCUMENT_ID>' // documentId
);

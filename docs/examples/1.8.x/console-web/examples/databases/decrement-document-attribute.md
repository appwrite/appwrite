import { Client, Databases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.decrementDocumentAttribute({
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    attribute: '',
    value: null,
    min: null
});

console.log(result);

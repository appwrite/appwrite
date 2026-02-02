import { Client, Databases, IndexType, OrderBy } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.createIndex({
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    key: '',
    type: IndexType.Key,
    attributes: [],
    orders: [OrderBy.Asc], // optional
    lengths: [] // optional
});

console.log(result);

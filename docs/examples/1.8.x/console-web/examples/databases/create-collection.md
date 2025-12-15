import { Client, Databases, Permission, Role } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.createCollection({
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    name: '<NAME>',
    permissions: [Permission.read(Role.any())], // optional
    documentSecurity: false, // optional
    enabled: false, // optional
    attributes: [], // optional
    indexes: [] // optional
});

console.log(result);

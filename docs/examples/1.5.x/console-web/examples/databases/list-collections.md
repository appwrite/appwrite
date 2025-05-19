import { Client, Databases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.listCollections(
    '<DATABASE_ID>', // databaseId
    [], // queries (optional)
    '<SEARCH>' // search (optional)
);

console.log(result);

import { Client, Databases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const databases = new Databases(client);

const result = await databases.listCollections(
    '<DATABASE_ID>', // databaseId
    [], // queries (optional)
    '<SEARCH>' // search (optional)
);

console.log(response);

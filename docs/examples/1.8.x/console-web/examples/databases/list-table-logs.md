import { Client, Databases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.listTableLogs(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    [] // queries (optional)
);

console.log(result);

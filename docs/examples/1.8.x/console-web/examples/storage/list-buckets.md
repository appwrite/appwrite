import { Client, Storage } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const storage = new Storage(client);

const result = await storage.listBuckets({
    queries: [], // optional
    search: '<SEARCH>', // optional
    total: false // optional
});

console.log(result);

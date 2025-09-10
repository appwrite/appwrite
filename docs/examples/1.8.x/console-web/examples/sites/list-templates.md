import { Client, Sites } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.listTemplates({
    frameworks: [], // optional
    useCases: [], // optional
    limit: 1, // optional
    offset: 0 // optional
});

console.log(result);

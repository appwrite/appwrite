import { Client, Sites } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.createVariable({
    siteId: '<SITE_ID>',
    key: '<KEY>',
    value: '<VALUE>',
    secret: false // optional
});

console.log(result);

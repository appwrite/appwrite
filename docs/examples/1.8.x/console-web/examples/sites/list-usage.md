import { Client, Sites, SiteUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.listUsage(
    SiteUsageRange.TwentyFourHours // range (optional)
);

console.log(result);

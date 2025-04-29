import { Client, Health } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const health = new Health(client);

const result = await health.getQueueMessaging(
    null // threshold (optional)
);

console.log(result);

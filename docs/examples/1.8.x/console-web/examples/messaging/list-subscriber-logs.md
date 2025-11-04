import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.listSubscriberLogs({
    subscriberId: '<SUBSCRIBER_ID>',
    queries: [], // optional
    total: false // optional
});

console.log(result);

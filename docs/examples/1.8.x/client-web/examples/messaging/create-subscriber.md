import { Client, Messaging } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createSubscriber({
    topicId: '<TOPIC_ID>',
    subscriberId: '<SUBSCRIBER_ID>',
    targetId: '<TARGET_ID>'
});

console.log(result);

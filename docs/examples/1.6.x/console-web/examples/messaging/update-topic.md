import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateTopic(
    '<TOPIC_ID>', // topicId
    '<NAME>', // name (optional)
    ["any"] // subscribe (optional)
);

console.log(result);

import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createTopic(
    '<TOPIC_ID>', // topicId
    '<NAME>', // name
    ["any"] // subscribe (optional)
);

console.log(result);

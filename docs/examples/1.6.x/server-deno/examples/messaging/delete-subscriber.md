import { Client, Messaging } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setJWT('&lt;YOUR_JWT&gt;'); // Your secret JSON Web Token

const messaging = new Messaging(client);

const response = await messaging.deleteSubscriber(
    '<TOPIC_ID>', // topicId
    '<SUBSCRIBER_ID>' // subscriberId
);

import { Client, Messaging } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...'); // Your secret JSON Web Token

const messaging = new Messaging(client);

const response = await messaging.createSubscriber(
    '<TOPIC_ID>', // topicId
    '<SUBSCRIBER_ID>', // subscriberId
    '<TARGET_ID>' // targetId
);

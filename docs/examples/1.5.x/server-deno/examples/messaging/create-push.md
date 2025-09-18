import { Client, Messaging, MessagePriority } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.createPush(
    '<MESSAGE_ID>', // messageId
    '<TITLE>', // title (optional)
    '<BODY>', // body (optional)
    [], // topics (optional)
    [], // users (optional)
    [], // targets (optional)
    {}, // data (optional)
    '<ACTION>', // action (optional)
    '[ID1:ID2]', // image (optional)
    '<ICON>', // icon (optional)
    '<SOUND>', // sound (optional)
    '<COLOR>', // color (optional)
    '<TAG>', // tag (optional)
    null, // badge (optional)
    false, // draft (optional)
    '', // scheduledAt (optional)
    false, // contentAvailable (optional)
    false, // critical (optional)
    MessagePriority.Normal // priority (optional)
);

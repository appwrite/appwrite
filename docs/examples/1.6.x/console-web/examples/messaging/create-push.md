import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createPush(
    '<MESSAGE_ID>', // messageId
    '<TITLE>', // title
    '<BODY>', // body
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
    '<BADGE>', // badge (optional)
    false, // draft (optional)
    '' // scheduledAt (optional)
);

console.log(result);

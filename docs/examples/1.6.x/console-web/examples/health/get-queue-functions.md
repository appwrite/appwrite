import { Client, Health } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const health = new Health(client);

const result = await health.getQueueFunctions(
    null // threshold (optional)
);

console.log(response);

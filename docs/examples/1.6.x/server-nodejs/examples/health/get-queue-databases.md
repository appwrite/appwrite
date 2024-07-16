const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

const health = new sdk.Health(client);

const result = await health.getQueueDatabases(
    '<NAME>', // name (optional)
    null // threshold (optional)
);

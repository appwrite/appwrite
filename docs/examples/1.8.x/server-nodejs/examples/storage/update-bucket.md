```javascript
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const storage = new sdk.Storage(client);

const result = await storage.updateBucket({
    bucketId: '<BUCKET_ID>',
    name: '<NAME>',
    permissions: [sdk.Permission.read(sdk.Role.any())], // optional
    fileSecurity: false, // optional
    enabled: false, // optional
    maximumFileSize: 1, // optional
    allowedFileExtensions: [], // optional
    compression: sdk.Compression.None, // optional
    encryption: false, // optional
    antivirus: false, // optional
    transformations: false // optional
});
```

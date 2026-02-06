```javascript
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const databases = new sdk.Databases(client);

const result = await databases.decrementDocumentAttribute({
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    attribute: '',
    value: null, // optional
    min: null, // optional
    transactionId: '<TRANSACTION_ID>' // optional
});
```

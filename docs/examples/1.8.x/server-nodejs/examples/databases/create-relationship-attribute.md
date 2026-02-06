```javascript
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const databases = new sdk.Databases(client);

const result = await databases.createRelationshipAttribute({
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    relatedCollectionId: '<RELATED_COLLECTION_ID>',
    type: sdk.RelationshipType.OneToOne,
    twoWay: false, // optional
    key: '', // optional
    twoWayKey: '', // optional
    onDelete: sdk.RelationMutate.Cascade // optional
});
```

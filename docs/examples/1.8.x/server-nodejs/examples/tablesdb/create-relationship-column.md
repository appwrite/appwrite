const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const tablesDB = new sdk.TablesDb(client);

const result = await tablesDB.createRelationshipColumn({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    relatedTableId: '<RELATED_TABLE_ID>',
    type: sdk.RelationshipType.OneToOne,
    twoWay: false,
    key: '',
    twoWayKey: '',
    onDelete: sdk.RelationMutate.Cascade
});

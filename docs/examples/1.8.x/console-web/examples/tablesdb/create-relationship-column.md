import { Client, TablesDB, RelationshipType, RelationMutate } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDB = new TablesDB(client);

const result = await tablesDB.createRelationshipColumn({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    relatedTableId: '<RELATED_TABLE_ID>',
    type: RelationshipType.OneToOne,
    twoWay: false, // optional
    key: '', // optional
    twoWayKey: '', // optional
    onDelete: RelationMutate.Cascade // optional
});

console.log(result);

import { Client, TablesDB, RelationshipType, RelationMutate } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const tablesDB = new TablesDB(client);

const response = await tablesDB.createRelationshipColumn({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    relatedTableId: '<RELATED_TABLE_ID>',
    type: RelationshipType.OneToOne,
    twoWay: false, // optional
    key: '', // optional
    twoWayKey: '', // optional
    onDelete: RelationMutate.Cascade // optional
});

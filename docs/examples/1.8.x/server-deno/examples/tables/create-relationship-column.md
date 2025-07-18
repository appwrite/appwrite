import { Client, Tables, RelationshipType, RelationMutate } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const tables = new Tables(client);

const response = await tables.createRelationshipColumn(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    '<RELATED_TABLE_ID>', // relatedTableId
    RelationshipType.OneToOne, // type
    false, // twoWay (optional)
    '', // key (optional)
    '', // twoWayKey (optional)
    RelationMutate.Cascade // onDelete (optional)
);

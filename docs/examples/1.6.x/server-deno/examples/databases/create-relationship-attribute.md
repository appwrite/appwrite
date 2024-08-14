import { Client, Databases, RelationshipType, RelationMutate } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

const databases = new Databases(client);

const response = await databases.createRelationshipAttribute(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '<RELATED_COLLECTION_ID>', // relatedCollectionId
    RelationshipType.OneToOne, // type
    false, // twoWay (optional)
    '', // key (optional)
    '', // twoWayKey (optional)
    RelationMutate.Cascade // onDelete (optional)
);

import { Client, Databases, RelationshipType, RelationMutate } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const databases = new Databases(client);

const result = await databases.createRelationshipAttribute(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '<RELATED_COLLECTION_ID>', // relatedCollectionId
    RelationshipType.OneToOne, // type
    false, // twoWay (optional)
    '', // key (optional)
    '', // twoWayKey (optional)
    RelationMutate.Cascade // onDelete (optional)
);

console.log(response);

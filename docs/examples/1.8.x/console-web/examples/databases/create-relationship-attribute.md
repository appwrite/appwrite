import { Client, Databases, RelationshipType, RelationMutate } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.createRelationshipAttribute({
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    relatedCollectionId: '<RELATED_COLLECTION_ID>',
    type: RelationshipType.OneToOne,
    twoWay: false, // optional
    key: '', // optional
    twoWayKey: '', // optional
    onDelete: RelationMutate.Cascade // optional
});

console.log(result);

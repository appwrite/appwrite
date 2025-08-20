import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

TablesDb tablesDb = TablesDb(client);

ColumnRelationship result = await tablesDb.createRelationshipColumn(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    relatedTableId: '<RELATED_TABLE_ID>',
    type: RelationshipType.oneToOne,
    twoWay: false, // (optional)
    key: '', // (optional)
    twoWayKey: '', // (optional)
    onDelete: RelationMutate.cascade, // (optional)
);

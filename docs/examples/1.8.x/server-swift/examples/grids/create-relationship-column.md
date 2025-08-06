import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let grids = Grids(client)

let columnRelationship = try await grids.createRelationshipColumn(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    relatedTableId: "<RELATED_TABLE_ID>",
    type: .oneToOne,
    twoWay: false, // optional
    key: "", // optional
    twoWayKey: "", // optional
    onDelete: .cascade // optional
)


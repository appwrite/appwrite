import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let tables = Tables(client)

let columnRelationship = try await tables.updateRelationshipColumn(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    key: "",
    onDelete: .cascade, // optional
    newKey: "" // optional
)


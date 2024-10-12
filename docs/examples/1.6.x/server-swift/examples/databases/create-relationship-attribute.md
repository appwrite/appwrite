import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let databases = Databases(client)

let attributeRelationship = try await databases.createRelationshipAttribute(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    relatedCollectionId: "<RELATED_COLLECTION_ID>",
    type: .oneToOne,
    twoWay: false, // optional
    key: "", // optional
    twoWayKey: "", // optional
    onDelete: .cascade // optional
)


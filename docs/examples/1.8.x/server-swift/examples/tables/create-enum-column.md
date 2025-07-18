import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let tables = Tables(client)

let columnEnum = try await tables.createEnumColumn(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    key: "",
    elements: [],
    required: false,
    default: "<DEFAULT>", // optional
    array: false // optional
)


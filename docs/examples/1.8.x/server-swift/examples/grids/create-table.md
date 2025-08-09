import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let grids = Grids(client)

let table = try await grids.createTable(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    name: "<NAME>",
    permissions: ["read("any")"], // optional
    rowSecurity: false, // optional
    enabled: false // optional
)


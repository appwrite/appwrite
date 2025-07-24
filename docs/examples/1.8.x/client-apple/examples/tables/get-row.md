import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let tables = Tables(client)

let row = try await tables.getRow(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rowId: "<ROW_ID>",
    queries: [] // optional
)


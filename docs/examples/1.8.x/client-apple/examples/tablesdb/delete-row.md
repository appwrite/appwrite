import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let tablesDB = TablesDB(client)

let result = try await tablesDB.deleteRow(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rowId: "<ROW_ID>"
)


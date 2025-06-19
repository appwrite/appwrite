import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setAdmin("") // 
    .setKey("") // 

let tables = Tables(client)

let rowList = try await tables.createRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rows: []
)


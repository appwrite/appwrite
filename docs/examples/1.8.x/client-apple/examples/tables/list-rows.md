import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let tables = Tables(client)

let rowList = try await tables.listRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    queries: [] // optional
)


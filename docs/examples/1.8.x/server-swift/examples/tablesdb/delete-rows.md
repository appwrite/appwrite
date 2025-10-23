import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let tablesDB = TablesDB(client)

let rowList = try await tablesDB.deleteRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    queries: [], // optional
    transactionId: "<TRANSACTION_ID>" // optional
)


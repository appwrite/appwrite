import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

let tablesDB = TablesDB(client)

let rowList = try await tablesDB.listRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    queries: [], // optional
    transactionId: "<TRANSACTION_ID>", // optional
    total: false // optional
)


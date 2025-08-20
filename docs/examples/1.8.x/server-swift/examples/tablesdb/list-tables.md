import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let tablesDb = TablesDb(client)

let tableList = try await tablesDb.listTables(
    databaseId: "<DATABASE_ID>",
    queries: [], // optional
    search: "<SEARCH>" // optional
)


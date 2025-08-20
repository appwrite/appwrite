using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

TablesDb tablesDb = new TablesDb(client);

RowList result = await tablesDb.UpdateRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    data: [object], // optional
    queries: new List<string>() // optional
);
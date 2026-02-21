using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

TablesDB tablesDB = new TablesDB(client);

Table result = await tablesDB.CreateTable(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    name: "<NAME>",
    permissions: new List<string> { Permission.Read(Role.Any()) }, // optional
    rowSecurity: false, // optional
    enabled: false, // optional
    columns: new List<object>(), // optional
    indexes: new List<object>() // optional
);
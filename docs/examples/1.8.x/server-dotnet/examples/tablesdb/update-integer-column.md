using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

TablesDB tablesDB = new TablesDB(client);

ColumnInteger result = await tablesDB.UpdateIntegerColumn(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    key: "",
    required: false,
    default: 0,
    min: 0, // optional
    max: 0, // optional
    newKey: "" // optional
);
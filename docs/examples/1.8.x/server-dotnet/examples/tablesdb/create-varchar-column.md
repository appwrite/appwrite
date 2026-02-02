using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

TablesDB tablesDB = new TablesDB(client);

ColumnVarchar result = await tablesDB.CreateVarcharColumn(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    key: "",
    size: 1,
    required: false,
    default: "<DEFAULT>", // optional
    array: false // optional
);
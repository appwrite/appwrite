using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetAdmin("") // 
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Tables tables = new Tables(client);

RowList result = await tables.CreateRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rows: new List<object>()
);
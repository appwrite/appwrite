using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

TablesDB tablesDB = new TablesDB(client);

RowList result = await tablesDB.UpdateRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    data: new {
        username = "walter.obrien",
        email = "walter.obrien@example.com",
        fullName = "Walter O'Brien",
        age = 33,
        isAdmin = false
    }, // optional
    queries: new List<string>(), // optional
    transactionId: "<TRANSACTION_ID>" // optional
);
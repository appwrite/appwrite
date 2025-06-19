using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Tables tables = new Tables(client);

Table result = await tables.Create(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    name: "<NAME>",
    permissions: ["read("any")"], // optional
    rowSecurity: false, // optional
    enabled: false // optional
);
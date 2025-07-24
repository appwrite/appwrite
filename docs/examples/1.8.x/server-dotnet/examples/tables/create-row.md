using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetSession("") // The user session to authenticate with
    .SetKey("<YOUR_API_KEY>") // Your secret API key
    .SetJWT("<YOUR_JWT>"); // Your secret JSON Web Token

Tables tables = new Tables(client);

Row result = await tables.CreateRow(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rowId: "<ROW_ID>",
    data: [object],
    permissions: ["read("any")"] // optional
);
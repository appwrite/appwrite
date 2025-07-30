using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Grids grids = new Grids(client);

DatabaseList result = await grids.ListDatabases(
    queries: new List<string>(), // optional
    search: "<SEARCH>" // optional
);
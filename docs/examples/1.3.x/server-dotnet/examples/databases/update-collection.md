using Appwrite;

Client client = new Client();

client
  .SetEndPoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .SetProject("5df5acd0d48c2") // Your project ID
  .SetKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
;

Databases databases = new Databases(client);

HttpResponseMessage result = await databases.UpdateCollection("[DATABASE_ID]", "[COLLECTION_ID]", "[NAME]");

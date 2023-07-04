using Appwrite;

Client client = new Client();

client
  .SetEndPoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .SetProject("5df5acd0d48c2") // Your project ID
  .SetKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
;

Users users = new Users(client);

HttpResponseMessage result = await users.CreateScryptModifiedUser("[USER_ID]", "email@example.com", "password", "[PASSWORD_SALT]", "[PASSWORD_SALT_SEPARATOR]", "[PASSWORD_SIGNER_KEY]");

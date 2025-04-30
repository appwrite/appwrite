import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Tokens tokens = Tokens(client);

Jwt result = await tokens.getJWT(
    tokenId: '<TOKEN_ID>',
);

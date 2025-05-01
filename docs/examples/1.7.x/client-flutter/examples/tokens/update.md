import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Tokens tokens = Tokens(client);

ResourceToken result = await tokens.update(
    tokenId: '<TOKEN_ID>',
    expire: '', // optional
    permissions: ["read("any")"], // optional
);

import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Account account = Account(client);

await account.createOAuth2Session(
    provider: OAuthProvider.amazon,
    success: 'https://example.com', // optional
    failure: 'https://example.com', // optional
    scopes: [], // optional
);

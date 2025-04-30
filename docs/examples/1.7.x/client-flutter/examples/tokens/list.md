import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Tokens tokens = Tokens(client);

ResourceTokenList result = await tokens.list(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    queries: [], // optional
);

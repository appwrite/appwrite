import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

Account account = Account(client);

Token result = await account.createRecovery(
    email: 'email@example.com',
    url: 'https://example.com',
);

import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

Account account = Account(client);

Token result = await account.createEmailToken(
    userId: '<USER_ID>',
    email: 'email@example.com',
    phrase: false, // optional
);

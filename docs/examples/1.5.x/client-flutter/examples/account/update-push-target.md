import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

Account account = Account(client);

Target result = await account.updatePushTarget(
    targetId: '<TARGET_ID>',
    identifier: '<IDENTIFIER>',
);

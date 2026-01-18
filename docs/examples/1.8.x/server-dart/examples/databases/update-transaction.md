import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Databases databases = Databases(client);

Transaction result = await databases.updateTransaction(
    transactionId: '<TRANSACTION_ID>',
    commit: false, // (optional)
    rollback: false, // (optional)
);

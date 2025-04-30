import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Functions functions = Functions(client);

ExecutionList result = await functions.listExecutions(
    functionId: '<FUNCTION_ID>',
    queries: [], // optional
);

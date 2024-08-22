import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Functions functions = Functions(client);

Execution result = await functions.getExecution(
    functionId: '<FUNCTION_ID>',
    executionId: '<EXECUTION_ID>',
);

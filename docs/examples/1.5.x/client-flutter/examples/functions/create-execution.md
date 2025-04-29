import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Functions functions = Functions(client);

Execution result = await functions.createExecution(
    functionId: '<FUNCTION_ID>',
    body: '<BODY>', // optional
    xasync: false, // optional
    path: '<PATH>', // optional
    method: ExecutionMethod.gET, // optional
    headers: {}, // optional
    scheduledAt: '', // optional
);

import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Functions functions = Functions(client);

TemplateFunctionList result = await functions.listTemplates(
    runtimes: [], // (optional)
    useCases: [], // (optional)
    limit: 1, // (optional)
    offset: 0, // (optional)
);

import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

Functions functions = Functions(client);

TemplateFunction result = await functions.getTemplate(
    templateId: '<TEMPLATE_ID>',
);

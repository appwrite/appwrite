import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Functions functions = Functions(client);

Deployment result = await functions.createDeployment(
    functionId: '<FUNCTION_ID>',
    code: InputFile(path: './path-to-files/image.jpg', filename: 'image.jpg'),
    activate: false,
    entrypoint: '<ENTRYPOINT>', // (optional)
    commands: '<COMMANDS>', // (optional)
);

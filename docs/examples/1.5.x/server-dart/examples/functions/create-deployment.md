import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

Functions functions = Functions(client);

Deployment result = await functions.createDeployment(
    functionId: '<FUNCTION_ID>',
    code: InputFile(path: './path-to-files/image.jpg', filename: 'image.jpg'),
    activate: false,
    entrypoint: '<ENTRYPOINT>', // (optional)
    commands: '<COMMANDS>', // (optional)
);

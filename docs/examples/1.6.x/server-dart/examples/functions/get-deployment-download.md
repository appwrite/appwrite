import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

Functions functions = Functions(client);

UInt8List result = await functions.getDeploymentDownload(
    functionId: '<FUNCTION_ID>',
    deploymentId: '<DEPLOYMENT_ID>',
);

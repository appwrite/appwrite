import 'dart:io';
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Sites sites = Sites(client);

Deployment result = await sites.createDeployment(
    siteId: '<SITE_ID>',
    code: InputFile(path: './path-to-files/image.jpg', filename: 'image.jpg'),
    activate: false,
    installCommand: '<INSTALL_COMMAND>', // (optional)
    buildCommand: '<BUILD_COMMAND>', // (optional)
    outputDirectory: '<OUTPUT_DIRECTORY>', // (optional)
);

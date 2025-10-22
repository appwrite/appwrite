import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Sites sites = Sites(client);

Deployment result = await sites.createTemplateDeployment(
    siteId: '<SITE_ID>',
    repository: '<REPOSITORY>',
    owner: '<OWNER>',
    rootDirectory: '<ROOT_DIRECTORY>',
    version: '<VERSION>',
    activate: false, // (optional)
);

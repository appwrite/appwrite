import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Functions functions = Functions(client);

Func result = await functions.create(
    functionId: '<FUNCTION_ID>',
    name: '<NAME>',
    runtime: .node145,
    execute: ["any"], // (optional)
    events: [], // (optional)
    schedule: '', // (optional)
    timeout: 1, // (optional)
    enabled: false, // (optional)
    logging: false, // (optional)
    entrypoint: '<ENTRYPOINT>', // (optional)
    commands: '<COMMANDS>', // (optional)
    scopes: [], // (optional)
    installationId: '<INSTALLATION_ID>', // (optional)
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>', // (optional)
    providerBranch: '<PROVIDER_BRANCH>', // (optional)
    providerSilentMode: false, // (optional)
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>', // (optional)
    templateRepository: '<TEMPLATE_REPOSITORY>', // (optional)
    templateOwner: '<TEMPLATE_OWNER>', // (optional)
    templateRootDirectory: '<TEMPLATE_ROOT_DIRECTORY>', // (optional)
    templateVersion: '<TEMPLATE_VERSION>', // (optional)
    specification: '', // (optional)
);

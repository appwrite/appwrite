import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setSession(''); // The user session to authenticate with

Storage storage = Storage(client);

await storage.deleteFile(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
);

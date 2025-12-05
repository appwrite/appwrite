import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

Storage storage = Storage(client);

Uint8List result = await storage.getFileDownload(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    token: '<TOKEN>', // (optional)
);

import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession(''); // The user session to authenticate with

Storage storage = Storage(client);

UInt8List result = await storage.getFileView(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
);

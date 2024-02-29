import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession(''); // The user session to authenticate with

Storage storage = Storage(client);

FileList result = await storage.listFiles(
    bucketId: '<BUCKET_ID>',
    queries: [], // (optional)
    search: '<SEARCH>', // (optional)
);

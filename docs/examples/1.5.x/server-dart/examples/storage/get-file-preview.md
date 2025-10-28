import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

Storage storage = Storage(client);

UInt8List result = await storage.getFilePreview(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    width: 0, // (optional)
    height: 0, // (optional)
    gravity: ImageGravity.center, // (optional)
    quality: 0, // (optional)
    borderWidth: 0, // (optional)
    borderColor: '', // (optional)
    borderRadius: 0, // (optional)
    opacity: 0, // (optional)
    rotation: -360, // (optional)
    background: '', // (optional)
    output: ImageFormat.jpg, // (optional)
);

import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/permission.dart';
import 'package:dart_appwrite/role.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Storage storage = Storage(client);

Bucket result = await storage.updateBucket(
    bucketId: '<BUCKET_ID>',
    name: '<NAME>',
    permissions: [Permission.read(Role.any())], // (optional)
    fileSecurity: false, // (optional)
    enabled: false, // (optional)
    maximumFileSize: 1, // (optional)
    allowedFileExtensions: [], // (optional)
    compression: Compression.none, // (optional)
    encryption: false, // (optional)
    antivirus: false, // (optional)
    transformations: false, // (optional)
);

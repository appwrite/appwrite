import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

Storage storage = Storage(client);

await storage.deleteFile(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
);

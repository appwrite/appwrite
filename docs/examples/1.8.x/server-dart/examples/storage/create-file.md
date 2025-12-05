import 'dart:io';
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/permission.dart';
import 'package:dart_appwrite/role.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

Storage storage = Storage(client);

File result = await storage.createFile(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    file: InputFile(path: './path-to-files/image.jpg', filename: 'image.jpg'),
    permissions: [Permission.read(Role.any())], // (optional)
);

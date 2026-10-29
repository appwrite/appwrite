import 'dart:io' show Platform;

import 'package:dart_appwrite/dart_appwrite.dart';

final appwriteClient = Client()
    .setEndpoint(Platform.environment['APPWRITE_ENDPOINT']!)
    .setProject(Platform.environment['APPWRITE_PROJECT_ID']!)
    .setKey(Platform.environment['APPWRITE_API_KEY']!);

final appwriteProject = Project(appwriteClient);

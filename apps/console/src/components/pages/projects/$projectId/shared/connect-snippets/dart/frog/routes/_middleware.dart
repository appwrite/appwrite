import 'dart:io' show Platform;

import 'package:dart_appwrite/dart_appwrite.dart' hide Response;
import 'package:dart_frog/dart_frog.dart';

final _client = Client()
    .setEndpoint(Platform.environment['APPWRITE_ENDPOINT']!)
    .setProject(Platform.environment['APPWRITE_PROJECT_ID']!)
    .setKey(Platform.environment['APPWRITE_API_KEY']!);

Handler middleware(Handler handler) {
  return handler.use(provider<Project>((_) => Project(_client)));
}

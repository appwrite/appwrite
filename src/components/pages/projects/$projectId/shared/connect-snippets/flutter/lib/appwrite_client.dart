import 'package:appwrite/appwrite.dart';

// dart:io's Platform.environment is empty in a released iOS/Android app, so
// the config is compiled in instead:
//   flutter run --dart-define-from-file=env.json
const _endpoint = String.fromEnvironment('APPWRITE_ENDPOINT');
const _projectId = String.fromEnvironment('APPWRITE_PROJECT_ID');

final client = Client()
  ..setEndpoint(_endpoint)
  ..setProject(_projectId);

import 'package:appwrite/appwrite.dart';

const _endpoint = String.fromEnvironment('APPWRITE_ENDPOINT');
const _projectId = String.fromEnvironment('APPWRITE_PROJECT_ID');

final client = Client()
  ..setEndpoint(_endpoint)
  ..setProject(_projectId);

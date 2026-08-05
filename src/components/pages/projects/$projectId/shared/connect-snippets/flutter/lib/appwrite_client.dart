import 'dart:io' show Platform;
import 'package:appwrite/appwrite.dart';

final client = Client()
  ..setEndpoint(Platform.environment['APPWRITE_ENDPOINT']!)
  ..setProject(Platform.environment['APPWRITE_PROJECT_ID']!);

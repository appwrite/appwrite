import 'dart:io' show Platform;
import 'package:appwrite/appwrite.dart';

final client = Client()
  ..setEndpoint(Platform.environment['APPWRITE_ENDPOINT']!)
  ..setProject(Platform.environment['APPWRITE_PROJECT_ID']!)
  ..setKey(Platform.environment['APPWRITE_API_KEY']!);

void main() async {
  final account = Account(client);
  final user = await account.get();
  print('Hello, ${user.name}');
}

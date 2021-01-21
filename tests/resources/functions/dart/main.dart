import 'dart:io';
import 'package:dart_appwrite/dart_appwrite.dart';

void main() { // Init SDK
  Client client = Client();

  client
    .setEndpoint(process.env.APPWRITE_ENDPOINT) // Your API Endpoint
    .setProject(process.env.APPWRITE_PROJECT) // Your project ID
    .setKey(process.env.APPWRITE_SECRET) // Your secret API key
  ;

  Storage storage = Storage(client);

  //Future result = storage.getFile(fileId: '[FILE_ID]');

  print(process.env.APPWRITE_FUNCTION_ID);
  print(process.env.APPWRITE_FUNCTION_NAME);
  print(process.env.APPWRITE_FUNCTION_TAG);
  print(process.env.APPWRITE_FUNCTION_TRIGGER);
  print(process.env.APPWRITE_FUNCTION_ENV_NAME);
  print(process.env.APPWRITE_FUNCTION_ENV_VERSION);
  // print(result['$id']);
  print(process.env.APPWRITE_FUNCTION_EVENT);
  print(process.env.APPWRITE_FUNCTION_EVENT_PAYLOAD);
}
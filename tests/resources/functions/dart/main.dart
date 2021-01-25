import "dart:io";
import "package:dart_appwrite/dart_appwrite.dart";

void main() { // Init SDK
  Client client = Client();

  client
    .setEndpoint(Platform.environment["APPWRITE_ENDPOINT"]) // Your API Endpoint
    .setProject(Platform.environment["APPWRITE_PROJECT"]) // Your project ID
    .setKey(Platform.environment["APPWRITE_SECRET"]) // Your secret API key
  ;

  Storage storage = Storage(client);

  //Future result = storage.getFile(fileId: '[FILE_ID]');

  print(Platform.environment["APPWRITE_FUNCTION_ID"]);
  print(Platform.environment["APPWRITE_FUNCTION_NAME"]);
  print(Platform.environment["APPWRITE_FUNCTION_TAG"]);
  print(Platform.environment["APPWRITE_FUNCTION_TRIGGER"]);
  print(Platform.environment["APPWRITE_FUNCTION_ENV_NAME"]);
  print(Platform.environment["APPWRITE_FUNCTION_ENV_VERSION"]);
  // print(result['$id']);
  print(Platform.environment["APPWRITE_FUNCTION_EVENT"]);
  print(Platform.environment["APPWRITE_FUNCTION_EVENT_PAYLOAD"]);
}
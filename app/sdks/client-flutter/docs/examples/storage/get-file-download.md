import 'package:appwrite/appwrite.dart';

// Init SDK
Client client = Client();
Storage storage = Storage(client);

client
    .setProject('5df5acd0d48c2') // Your project ID
;

String result = storage.getFileDownload(
    fileId: '[FILE_ID]',
);

print(result); // Resource URL string

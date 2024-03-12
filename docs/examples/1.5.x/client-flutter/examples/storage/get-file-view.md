import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

Storage storage = Storage(client);

// Downloading file
UInt8List bytes = await storage.getFileView(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
)

final file = File('path_to_file/filename.ext');
file.writeAsBytesSync(bytes);

// Displaying image preview
FutureBuilder(
    future: storage.getFileView(
    bucketId:'<BUCKET_ID>' ,
    fileId:'<FILE_ID>' ,
), // Works for both public file and private file, for private files you need to be logged in
    builder: (context, snapshot) {
      return snapshot.hasData && snapshot.data != null
          ? Image.memory(snapshot.data)
          : CircularProgressIndicator();
    }
);

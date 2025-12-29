import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Storage storage = Storage(client);

// Downloading file
Uint8List bytes = await storage.getFileDownload(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    token: '<TOKEN>', // optional
)

final file = File('path_to_file/filename.ext');
file.writeAsBytesSync(bytes);

// Displaying image preview
FutureBuilder(
    future: storage.getFileDownload(
    bucketId:'<BUCKET_ID>' ,
    fileId:'<FILE_ID>' ,
    token:'<TOKEN>' , // optional
), // Works for both public file and private file, for private files you need to be logged in
    builder: (context, snapshot) {
      return snapshot.hasData && snapshot.data != null
          ? Image.memory(snapshot.data)
          : CircularProgressIndicator();
    }
);

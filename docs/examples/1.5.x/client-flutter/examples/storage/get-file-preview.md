import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

Storage storage = Storage(client);

// Downloading file
UInt8List bytes = await storage.getFilePreview(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    width: 0, // optional
    height: 0, // optional
    gravity: ImageGravity.center, // optional
    quality: 0, // optional
    borderWidth: 0, // optional
    borderColor: '', // optional
    borderRadius: 0, // optional
    opacity: 0, // optional
    rotation: -360, // optional
    background: '', // optional
    output: ImageFormat.jpg, // optional
)

final file = File('path_to_file/filename.ext');
file.writeAsBytesSync(bytes);

// Displaying image preview
FutureBuilder(
    future: storage.getFilePreview(
    bucketId:'<BUCKET_ID>' ,
    fileId:'<FILE_ID>' ,
    width:0 , // optional
    height:0 , // optional
    gravity: ImageGravity.center, // optional
    quality:0 , // optional
    borderWidth:0 , // optional
    borderColor:'' , // optional
    borderRadius:0 , // optional
    opacity:0 , // optional
    rotation:-360 , // optional
    background:'' , // optional
    output: ImageFormat.jpg, // optional
), // Works for both public file and private file, for private files you need to be logged in
    builder: (context, snapshot) {
      return snapshot.hasData && snapshot.data != null
          ? Image.memory(snapshot.data)
          : CircularProgressIndicator();
    }
);

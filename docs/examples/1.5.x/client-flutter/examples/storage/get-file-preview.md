import 'package:appwrite/appwrite.dart';

Client client = Client()
  .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
  .setProject('5df5acd0d48c2'); // Your project ID

Storage storage = Storage(client);

// Downloading file
Future result = storage.getFilePreview(
  bucketId: '<BUCKET_ID>',
  fileId: '<FILE_ID>',
  width: 0, // (optional)
  height: 0, // (optional)
  gravity: ImageGravity.center, // (optional)
  quality: 0, // (optional)
  borderWidth: 0, // (optional)
  borderColor: '', // (optional)
  borderRadius: 0, // (optional)
  opacity: 0, // (optional)
  rotation: -360, // (optional)
  background: '', // (optional)
  output: ImageFormat.jpg, // (optional)
).then((bytes) {
  final file = File('path_to_file/filename.ext');
  file.writeAsBytesSync(bytes)
}).catchError((error) {
    print(error.response);
})

// Displaying image preview
FutureBuilder(
  future: storage.getFilePreview(
  bucketId:'<BUCKET_ID>' ,
  fileId:'<FILE_ID>' ,
  width:0 , // (optional)
  height:0 , // (optional)
  gravity: ImageGravity.center.value, // (optional)
  quality:0 , // (optional)
  borderWidth:0 , // (optional)
  borderColor:'' , // (optional)
  borderRadius:0 , // (optional)
  opacity:0 , // (optional)
  rotation:-360 , // (optional)
  background:'' , // (optional)
  output: ImageFormat.jpg.value, // (optional)
), // Works for both public file and private file, for private files you need to be logged in
  builder: (context, snapshot) {
    return snapshot.hasData && snapshot.data != null
      ? Image.memory(
          snapshot.data,
        )
      : CircularProgressIndicator();
  }
);

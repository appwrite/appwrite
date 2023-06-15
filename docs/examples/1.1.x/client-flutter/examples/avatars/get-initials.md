import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Avatars avatars = Avatars(client);

  client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
  ;
  // downloading file
  Future result = avatars.getInitials(
  ).then((bytes) {
    final file = File('path_to_file/filename.ext');
    file.writeAsBytesSync(bytes)
  }).catchError((error) {
      print(error.response);
  })
}

//displaying image preview
FutureBuilder(
  future: avatars.getInitials(
  ), //works for both public file and private file, for private files you need to be logged in
  builder: (context, snapshot) {
    return snapshot.hasData && snapshot.data != null
      ? Image.memory(
          snapshot.data,
        )
      : CircularProgressIndicator();
  },
);

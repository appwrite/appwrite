import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Avatars avatars = Avatars(client);

  client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
  ;
}

//displaying image
FutureBuilder(
  future: avatars.getBrowser(
    code: 'aa',
  ), //works for both public file and private file, for private files you need to be logged in
  builder: (context, snapshot) {
    return snapshot.hasData && snapshot.data != null
      ? Image.memory(
          snapshot.data.data,
        )
      : CircularProgressIndicator();
  },
);

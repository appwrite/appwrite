import 'dart:io';
import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Storage storage = Storage(client);

  client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
  ;
  Future result = storage.createFile(
    file: await MultipartFile.fromFile('./path-to-files/image.jpg', 'image.jpg'),
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}

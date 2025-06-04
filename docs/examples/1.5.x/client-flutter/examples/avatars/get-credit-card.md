import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Avatars avatars = Avatars(client);

// Downloading file
UInt8List bytes = await avatars.getCreditCard(
    code: CreditCard.americanExpress,
    width: 0, // optional
    height: 0, // optional
    quality: 0, // optional
)

final file = File('path_to_file/filename.ext');
file.writeAsBytesSync(bytes);

// Displaying image preview
FutureBuilder(
    future: avatars.getCreditCard(
    code: CreditCard.americanExpress,
    width:0 , // optional
    height:0 , // optional
    quality:0 , // optional
), // Works for both public file and private file, for private files you need to be logged in
    builder: (context, snapshot) {
      return snapshot.hasData && snapshot.data != null
          ? Image.memory(snapshot.data)
          : CircularProgressIndicator();
    }
);

import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Avatars avatars = Avatars(client);

// Downloading file
Uint8List bytes = await avatars.getScreenshot(
    url: 'https://example.com',
    headers: {}, // optional
    viewportWidth: 1, // optional
    viewportHeight: 1, // optional
    scale: 0.1, // optional
    theme: Theme.light, // optional
    userAgent: '<USER_AGENT>', // optional
    fullpage: false, // optional
    locale: '<LOCALE>', // optional
    timezone: Timezone.africaAbidjan, // optional
    latitude: -90, // optional
    longitude: -180, // optional
    accuracy: 0, // optional
    touch: false, // optional
    permissions: [], // optional
    sleep: 0, // optional
    width: 0, // optional
    height: 0, // optional
    quality: -1, // optional
    output: Output.jpg, // optional
)

final file = File('path_to_file/filename.ext');
file.writeAsBytesSync(bytes);

// Displaying image preview
FutureBuilder(
    future: avatars.getScreenshot(
    url:'https://example.com' ,
    headers:{} , // optional
    viewportWidth:1 , // optional
    viewportHeight:1 , // optional
    scale:0.1 , // optional
    theme: Theme.light, // optional
    userAgent:'<USER_AGENT>' , // optional
    fullpage:false , // optional
    locale:'<LOCALE>' , // optional
    timezone: Timezone.africaAbidjan, // optional
    latitude:-90 , // optional
    longitude:-180 , // optional
    accuracy:0 , // optional
    touch:false , // optional
    permissions:[] , // optional
    sleep:0 , // optional
    width:0 , // optional
    height:0 , // optional
    quality:-1 , // optional
    output: Output.jpg, // optional
), // Works for both public file and private file, for private files you need to be logged in
    builder: (context, snapshot) {
      return snapshot.hasData && snapshot.data != null
          ? Image.memory(snapshot.data)
          : CircularProgressIndicator();
    }
);

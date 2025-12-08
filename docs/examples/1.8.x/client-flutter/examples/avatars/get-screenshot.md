import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Avatars avatars = Avatars(client);

// Downloading file
Uint8List bytes = await avatars.getScreenshot(
    url: 'https://example.com',
    headers: {
        "Authorization": "Bearer token123",
        "X-Custom-Header": "value"
    }, // optional
    viewportWidth: 1920, // optional
    viewportHeight: 1080, // optional
    scale: 2, // optional
    theme: Theme.light, // optional
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15', // optional
    fullpage: true, // optional
    locale: 'en-US', // optional
    timezone: Timezone.africaAbidjan, // optional
    latitude: 37.7749, // optional
    longitude: -122.4194, // optional
    accuracy: 100, // optional
    touch: true, // optional
    permissions: ["geolocation","notifications"], // optional
    sleep: 3, // optional
    width: 800, // optional
    height: 600, // optional
    quality: 85, // optional
    output: Output.jpg, // optional
)

final file = File('path_to_file/filename.ext');
file.writeAsBytesSync(bytes);

// Displaying image preview
FutureBuilder(
    future: avatars.getScreenshot(
    url:'https://example.com' ,
    headers:{
        "Authorization": "Bearer token123",
        "X-Custom-Header": "value"
    } , // optional
    viewportWidth:1920 , // optional
    viewportHeight:1080 , // optional
    scale:2 , // optional
    theme: Theme.light, // optional
    userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15' , // optional
    fullpage:true , // optional
    locale:'en-US' , // optional
    timezone: Timezone.africaAbidjan, // optional
    latitude:37.7749 , // optional
    longitude:-122.4194 , // optional
    accuracy:100 , // optional
    touch:true , // optional
    permissions:["geolocation","notifications"] , // optional
    sleep:3 , // optional
    width:800 , // optional
    height:600 , // optional
    quality:85 , // optional
    output: Output.jpg, // optional
), // Works for both public file and private file, for private files you need to be logged in
    builder: (context, snapshot) {
      return snapshot.hasData && snapshot.data != null
          ? Image.memory(snapshot.data)
          : CircularProgressIndicator();
    }
);

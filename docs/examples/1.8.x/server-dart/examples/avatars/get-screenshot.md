import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

Avatars avatars = Avatars(client);

UInt8List result = await avatars.getScreenshot(
    url: 'https://example.com',
    headers: {}, // (optional)
    viewportWidth: 1, // (optional)
    viewportHeight: 1, // (optional)
    scale: 0.1, // (optional)
    theme: .light, // (optional)
    userAgent: '<USER_AGENT>', // (optional)
    fullpage: false, // (optional)
    locale: '<LOCALE>', // (optional)
    timezone: .africaAbidjan, // (optional)
    latitude: -90, // (optional)
    longitude: -180, // (optional)
    accuracy: 0, // (optional)
    touch: false, // (optional)
    permissions: [], // (optional)
    sleep: 0, // (optional)
    width: 0, // (optional)
    height: 0, // (optional)
    quality: -1, // (optional)
    output: .jpg, // (optional)
);

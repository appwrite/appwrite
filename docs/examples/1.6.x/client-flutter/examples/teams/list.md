import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Teams teams = Teams(client);

TeamList result = await teams.list(
    queries: [], // optional
    search: '<SEARCH>', // optional
);

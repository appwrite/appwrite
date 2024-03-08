import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

Teams teams = Teams(client);

TeamList result = await teams.list(
    queries: [], // optional
    search: '<SEARCH>', // optional
);

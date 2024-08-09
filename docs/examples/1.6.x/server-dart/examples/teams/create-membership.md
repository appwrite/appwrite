import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setSession(''); // The user session to authenticate with

Teams teams = Teams(client);

Membership result = await teams.createMembership(
    teamId: '<TEAM_ID>',
    roles: [],
    email: 'email@example.com', // (optional)
    userId: '<USER_ID>', // (optional)
    phone: '+12065550100', // (optional)
    url: 'https://example.com', // (optional)
    name: '<NAME>', // (optional)
);

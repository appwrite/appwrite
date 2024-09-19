import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Teams teams = Teams(client);

Membership result = await teams.createMembership(
    teamId: '<TEAM_ID>',
    roles: [],
    email: 'email@example.com', // optional
    userId: '<USER_ID>', // optional
    phone: '+12065550100', // optional
    url: 'https://example.com', // optional
    name: '<NAME>', // optional
);

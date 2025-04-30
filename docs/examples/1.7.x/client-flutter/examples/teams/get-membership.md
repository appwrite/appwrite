import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Teams teams = Teams(client);

Membership result = await teams.getMembership(
    teamId: '<TEAM_ID>',
    membershipId: '<MEMBERSHIP_ID>',
);

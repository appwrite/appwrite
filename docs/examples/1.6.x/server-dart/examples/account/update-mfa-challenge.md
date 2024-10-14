import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

Account account = Account(client);

 result = await account.updateMfaChallenge(
    challengeId: '<CHALLENGE_ID>',
    otp: '<OTP>',
);

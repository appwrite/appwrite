import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

Account account = Account(client);

User result = await account.verifyAuthenticator(
    type: AuthenticatorType.totp,
    otp: '<OTP>',
);

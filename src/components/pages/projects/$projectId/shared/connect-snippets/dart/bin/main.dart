import 'dart:io' show Platform;
import 'package:appwrite/appwrite.dart';

final client = Client()
  ..setEndpoint(Platform.environment['APPWRITE_ENDPOINT']!)
  ..setProject(Platform.environment['APPWRITE_PROJECT_ID']!)
  ..setKey(Platform.environment['APPWRITE_API_KEY']!);

void main() async {
  final project = Project(client);

  final policy = await project.updatePasswordStrengthPolicy(
    min: 8,
    uppercase: true,
    number: true,
    symbols: true,
  );

  print(policy);

  final policies = await project.listPolicies();

  print(policies);
}

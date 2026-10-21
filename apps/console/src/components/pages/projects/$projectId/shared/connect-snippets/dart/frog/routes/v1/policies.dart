import 'package:dart_appwrite/dart_appwrite.dart' hide Response;
import 'package:dart_frog/dart_frog.dart';

Future<Response> onRequest(RequestContext context) async {
  final project = context.read<Project>();

  if (context.request.method == HttpMethod.patch) {
    final policy = await project.updatePasswordStrengthPolicy(
      min: 8,
      uppercase: true,
      number: true,
      symbols: true,
    );

    return Response.json(body: policy.toMap());
  }

  final policies = await project.listPolicies();

  return Response.json(body: policies.toMap());
}

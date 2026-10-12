import 'dart:convert';

import 'package:relic/relic.dart';
import 'package:serverpod/serverpod.dart';

import '../appwrite.dart';

class PoliciesRoute extends Route {
  PoliciesRoute() : super(methods: {Method.get, Method.patch});

  @override
  Future<Result> handleCall(Session session, Request request) async {
    if (request.method == Method.patch) {
      final policy = await appwriteProject.updatePasswordStrengthPolicy(
        min: 8,
        uppercase: true,
        number: true,
        symbols: true,
      );

      return _json(policy.toMap());
    }

    final policies = await appwriteProject.listPolicies();

    return _json(policies.toMap());
  }

  Response _json(Map<String, dynamic> body) => Response.ok(
        body: Body.fromString(jsonEncode(body), mimeType: MimeType.json),
      );
}

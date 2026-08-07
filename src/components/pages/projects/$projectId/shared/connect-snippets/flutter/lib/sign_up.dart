import 'package:flutter/material.dart';
import 'package:appwrite/appwrite.dart';
import 'appwrite_client.dart';

class SignUp extends StatefulWidget {
  const SignUp({
    super.key,
    required this.onSignedUp,
    required this.onGoToSignIn,
  });

  final VoidCallback onSignedUp;
  final VoidCallback onGoToSignIn;

  @override
  State<SignUp> createState() => _SignUpState();
}

class _SignUpState extends State<SignUp> {
  final name = TextEditingController();
  final email = TextEditingController();
  final password = TextEditingController();
  String error = '';

  @override
  void dispose() {
    name.dispose();
    email.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (email.text.isEmpty || password.text.isEmpty) return;
    setState(() => error = '');
    try {
      final account = Account(client);
      await account.create(
        userId: ID.unique(),
        email: email.text,
        password: password.text,
        name: name.text.trim().isEmpty ? null : name.text.trim(),
      );
      await account.createEmailPasswordSession(
        email: email.text,
        password: password.text,
      );
      widget.onSignedUp();
    } on AppwriteException catch (e) {
      setState(() => error = e.message ?? 'Sign up failed');
    } catch (_) {
      setState(() => error = 'Sign up failed');
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        const Text('Sign up'),
        if (error.isNotEmpty) Text(error),
        TextField(
          controller: name,
          decoration: const InputDecoration(hintText: 'Name'),
        ),
        TextField(
          controller: email,
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          decoration: const InputDecoration(hintText: 'Email'),
        ),
        TextField(
          controller: password,
          obscureText: true,
          decoration: const InputDecoration(hintText: 'Password'),
        ),
        TextButton(onPressed: _submit, child: const Text('Sign up')),
        Row(
          children: [
            const Text('Already have an account? '),
            TextButton(
              onPressed: widget.onGoToSignIn,
              child: const Text('Sign in'),
            ),
          ],
        ),
      ],
    );
  }
}

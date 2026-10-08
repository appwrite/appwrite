import 'package:flutter/material.dart';
import 'package:appwrite/appwrite.dart';
import 'appwrite_client.dart';

class SignIn extends StatefulWidget {
  const SignIn({
    super.key,
    required this.onSignedIn,
    required this.onGoToSignUp,
  });

  final VoidCallback onSignedIn;
  final VoidCallback onGoToSignUp;

  @override
  State<SignIn> createState() => _SignInState();
}

class _SignInState extends State<SignIn> {
  final email = TextEditingController();
  final password = TextEditingController();
  String error = '';

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (email.text.isEmpty || password.text.isEmpty) return;
    setState(() => error = '');
    try {
      await Account(client).createEmailPasswordSession(
        email: email.text,
        password: password.text,
      );
      widget.onSignedIn();
    } on AppwriteException catch (e) {
      setState(() => error = e.message ?? 'Sign in failed');
    } catch (_) {
      setState(() => error = 'Sign in failed');
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        const Text('Sign in'),
        if (error.isNotEmpty) Text(error),
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
        TextButton(onPressed: _submit, child: const Text('Sign in')),
        Row(
          children: [
            const Text('No account? '),
            TextButton(
              onPressed: widget.onGoToSignUp,
              child: const Text('Sign up'),
            ),
          ],
        ),
      ],
    );
  }
}

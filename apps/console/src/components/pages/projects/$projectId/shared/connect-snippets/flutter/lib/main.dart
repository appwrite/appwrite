import 'package:flutter/material.dart';
import 'package:appwrite/appwrite.dart';
import 'appwrite_client.dart';
import 'sign_in.dart';
import 'sign_up.dart';

void main() => runApp(const MyApp());

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return const MaterialApp(home: Scaffold(body: SafeArea(child: Router())));
  }
}

class Router extends StatefulWidget {
  const Router({super.key});

  @override
  State<Router> createState() => _RouterState();
}

class _RouterState extends State<Router> {
  String route = 'home';

  void go(String next) => setState(() => route = next);

  @override
  Widget build(BuildContext context) {
    if (route == 'sign-in') {
      return SignIn(
        onSignedIn: () => go('home'),
        onGoToSignUp: () => go('sign-up'),
      );
    }
    if (route == 'sign-up') {
      return SignUp(
        onSignedUp: () => go('home'),
        onGoToSignIn: () => go('sign-in'),
      );
    }
    return Home(
      onGoToSignIn: () => go('sign-in'),
      onGoToSignUp: () => go('sign-up'),
    );
  }
}

class Home extends StatefulWidget {
  const Home({
    super.key,
    required this.onGoToSignIn,
    required this.onGoToSignUp,
  });

  final VoidCallback onGoToSignIn;
  final VoidCallback onGoToSignUp;

  @override
  State<Home> createState() => _HomeState();
}

class _HomeState extends State<Home> {
  String? name;
  bool loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final user = await Account(client).get();
      if (mounted) setState(() => name = user.name);
    } catch (_) {
      if (mounted) setState(() => name = null);
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> _signOut() async {
    await Account(client).deleteSession(sessionId: 'current');
    if (mounted) setState(() => name = null);
  }

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Center(child: Text('Loading...'));
    }

    if (name == null) {
      return Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Text('Sign in to get started.'),
          TextButton(
            onPressed: widget.onGoToSignIn,
            child: const Text('Sign in'),
          ),
          TextButton(
            onPressed: widget.onGoToSignUp,
            child: const Text('Sign up'),
          ),
        ],
      );
    }

    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text('Hello, $name'),
        TextButton(onPressed: _signOut, child: const Text('Sign out')),
      ],
    );
  }
}

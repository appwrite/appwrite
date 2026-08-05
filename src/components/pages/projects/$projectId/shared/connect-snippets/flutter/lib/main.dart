import 'package:flutter/material.dart';
import 'appwrite_client.dart';
import 'package:appwrite/appwrite.dart';

void main() => runApp(const MyApp());

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      home: Scaffold(
        body: Center(
          child: FutureBuilder(
            future: Account(client).get(),
            builder: (context, snapshot) {
              if (snapshot.hasData) {
                return Text('Hello, ${snapshot.data!.name}');
              }
              return const Text('Sign in to get started.');
            },
          ),
        ),
      ),
    );
  }
}

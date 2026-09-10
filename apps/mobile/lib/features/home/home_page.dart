import 'package:flutter/material.dart';

/// Neutral placeholder home screen. Replaced by real navigation/screens
/// once business modules are authorized (see docs/ROADMAP.md).
class HomePage extends StatelessWidget {
  const HomePage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Company App')),
      body: const Center(child: Text('Company App — bootstrap shell')),
    );
  }
}

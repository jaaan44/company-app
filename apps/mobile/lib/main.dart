import 'package:flutter/material.dart';

void main() {
  runApp(const CompanyApp());
}

/// Root widget of the Company App staff mobile application.
///
/// This is a minimal bootstrap shell (Phase 1 — Project Bootstrap).
/// No business features/screens are implemented yet; see docs/ROADMAP.md.
class CompanyApp extends StatelessWidget {
  const CompanyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Company App',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo),
      ),
      home: const BootstrapHomePage(),
    );
  }
}

/// Neutral placeholder home screen. Replaced by real navigation/screens
/// in a later phase (see docs/ROADMAP.md, Phase 3 — Core Architecture).
class BootstrapHomePage extends StatelessWidget {
  const BootstrapHomePage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Company App')),
      body: const Center(child: Text('Company App — bootstrap shell')),
    );
  }
}

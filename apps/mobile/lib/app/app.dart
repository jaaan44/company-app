import 'package:flutter/material.dart';

import 'package:mobile/features/home/home_page.dart';

/// Root widget of the Company App staff mobile application.
///
/// Foundation only (Phase 3 — Core Architecture): no routing package and
/// no state-management framework are introduced here — Flutter's built-in
/// navigation is sufficient until a real feature (starting with
/// Authentication) needs more. See docs/02_ARCHITECTURE.md.
class CompanyApp extends StatelessWidget {
  const CompanyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Company App',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo),
      ),
      home: const HomePage(),
    );
  }
}

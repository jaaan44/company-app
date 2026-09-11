import 'package:flutter/material.dart';

/// Neutral placeholder home screen. Replaced by real navigation/screens
/// once business modules are authorized (see docs/ROADMAP.md). As of
/// Phase 4, this is also the authenticated destination [AuthGate] shows
/// after login — still just a placeholder, not the employee dashboard.
class HomePage extends StatelessWidget {
  const HomePage({super.key, this.userName, this.onLogout});

  final String? userName;
  final VoidCallback? onLogout;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Company App'),
        actions: [
          if (onLogout != null)
            IconButton(
              icon: const Icon(Icons.logout),
              tooltip: 'Log out',
              onPressed: onLogout,
            ),
        ],
      ),
      body: Center(
        child: Text(
          userName != null
              ? 'Signed in as $userName'
              : 'Company App — bootstrap shell',
        ),
      ),
    );
  }
}

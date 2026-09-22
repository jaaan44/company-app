import 'package:flutter/material.dart';

import 'package:mobile/app/auth_scope.dart';

/// Neutral placeholder home screen. Replaced with real dashboard content by
/// Phase 27 (see docs/ROADMAP.md). As of Phase 25, this is the `/home`
/// destination of the bottom-navigation shell and reads the shared
/// [AuthScope] instead of taking `userName`/`onLogout` constructor
/// parameters — its former caller, [AuthGate], no longer exists.
class HomePage extends StatelessWidget {
  const HomePage({super.key});

  @override
  Widget build(BuildContext context) {
    final authController = AuthScope.of(context);
    final userName = authController.user?.name;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Company App'),
        actions: [
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Log out',
            onPressed: authController.logout,
          ),
        ],
      ),
      body: Center(
        child: Text(
          userName != null
              ? 'Signed in as $userName'
              : 'Company App — bootstrap shell',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
      ),
    );
  }
}

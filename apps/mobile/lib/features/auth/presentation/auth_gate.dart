import 'package:flutter/material.dart';

import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/home_page.dart';

/// Root decision point between the login screen and the authenticated
/// placeholder shell, driven by [AuthController.status] (Phase 4 flow —
/// see docs/handoffs/V1_PHASE_04_HANDOFF.md).
class AuthGate extends StatefulWidget {
  const AuthGate({super.key, required this.controller});

  final AuthController controller;

  @override
  State<AuthGate> createState() => _AuthGateState();
}

class _AuthGateState extends State<AuthGate> {
  @override
  void initState() {
    super.initState();
    widget.controller.bootstrap();
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.controller,
      builder: (context, _) {
        switch (widget.controller.status) {
          case AuthStatus.unknown:
            return const Scaffold(
              body: Center(child: CircularProgressIndicator()),
            );
          case AuthStatus.unauthenticated:
          case AuthStatus.authenticating:
            return LoginPage(controller: widget.controller);
          case AuthStatus.authenticated:
            return HomePage(
              userName: widget.controller.user?.name,
              onLogout: widget.controller.logout,
            );
        }
      },
    );
  }
}

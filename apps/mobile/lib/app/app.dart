import 'package:flutter/material.dart';

import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/data/token_storage.dart';
import 'package:mobile/features/auth/presentation/auth_gate.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';

/// Root widget of the Company App staff mobile application.
///
/// As of Phase 4 (Authentication), this owns the single [AuthController]
/// instance for the app's lifetime and hands it to [AuthGate], which
/// decides between the login screen and the authenticated placeholder
/// shell. No routing package and no third-party state-management
/// framework (Provider/Riverpod/Bloc) are introduced — a plain
/// [ChangeNotifier] is sufficient for this phase's one piece of shared
/// state; see DEC-024.
class CompanyApp extends StatefulWidget {
  /// [authController] is exposed for tests to inject a controller wired to
  /// a fake API client/token storage — production code always omits it and
  /// gets the real Sanctum-backed implementation below.
  const CompanyApp({super.key, AuthController? authController})
    : _injectedAuthController = authController;

  final AuthController? _injectedAuthController;

  @override
  State<CompanyApp> createState() => _CompanyAppState();
}

class _CompanyAppState extends State<CompanyApp> {
  late final AuthController _authController =
      widget._injectedAuthController ??
      AuthController(
        apiClient: AuthApiClient(),
        tokenStorage: SecureTokenStorage(),
      );

  @override
  void dispose() {
    _authController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Company App',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo),
      ),
      home: AuthGate(controller: _authController),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/app/auth_scope.dart';
import 'package:mobile/app/router.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/data/token_storage.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/data/home_api_client.dart';

/// Root widget of the Company App staff mobile application.
///
/// As of Phase 25 (Mobile Application Foundation & Navigation Shell), this
/// owns the single [AuthController] instance for the app's lifetime (as
/// before) plus the single [GoRouter] instance built from it —
/// `go_router`'s `StatefulShellRoute`-based bottom-navigation shell is now
/// the app's sole navigation mechanism, replacing the former [AuthGate]
/// widget-switch with declarative, route-level auth redirects (see
/// lib/app/router.dart). [AuthScope] makes the same [AuthController]
/// available to every route the router builds. No routing package was
/// used before this phase (DEC-021's deferral, resolved by DEC-046), and
/// no third-party state-management framework is introduced now either —
/// a plain [ChangeNotifier] remains sufficient; see DEC-025.
class CompanyApp extends StatefulWidget {
  /// [authController] and [homeApiClient] are exposed for tests to inject
  /// fakes — production code always omits them and gets the real
  /// Sanctum-backed implementations below.
  const CompanyApp({
    super.key,
    AuthController? authController,
    HomeApiClient? homeApiClient,
  }) : _injectedAuthController = authController,
       _injectedHomeApiClient = homeApiClient;

  final AuthController? _injectedAuthController;
  final HomeApiClient? _injectedHomeApiClient;

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

  // The authenticated API client (Phase 27) takes its token and session
  // rules from the same AuthController.
  late final HomeApiClient _homeApiClient =
      widget._injectedHomeApiClient ??
      HomeApiClient(ApiClient(session: _authController));

  late final GoRouter _router = buildAppRouter(
    _authController,
    homeApiClient: _homeApiClient,
  );

  @override
  void initState() {
    super.initState();
    // Kicks off the stored-token check every app launch (formerly
    // AuthGate.initState) — the router's redirect shows `/splash` until
    // this resolves, avoiding a flash of the wrong screen.
    _authController.bootstrap();
  }

  @override
  void dispose() {
    _router.dispose();
    _authController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AuthScope(
      controller: _authController,
      child: MaterialApp.router(
        title: 'Company App',
        theme: ThemeData(
          colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo),
        ),
        darkTheme: ThemeData(
          colorScheme: ColorScheme.fromSeed(
            seedColor: Colors.indigo,
            brightness: Brightness.dark,
          ),
        ),
        themeMode: ThemeMode.system,
        routerConfig: _router,
      ),
    );
  }
}

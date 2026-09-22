import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/home_page.dart';
import 'package:mobile/features/shell/presentation/app_shell.dart';
import 'package:mobile/features/shell/presentation/placeholder_page.dart';

/// Builds the app's single [GoRouter] — the sole navigation mechanism
/// (Phase 25, DEC-046), replacing the former [AuthGate] widget-switch with
/// declarative, route-level auth redirects. [authController] is also
/// wired in as `refreshListenable` so every status change (bootstrap
/// resolving, login, logout) re-evaluates [_redirect] automatically.
GoRouter buildAppRouter(AuthController authController) {
  return GoRouter(
    initialLocation: '/splash',
    refreshListenable: authController,
    redirect: (context, state) => _redirect(authController, state),
    routes: [
      GoRoute(
        path: '/splash',
        builder: (context, state) => const _SplashPage(),
      ),
      GoRoute(
        path: '/login',
        builder: (context, state) => LoginPage(controller: authController),
      ),
      StatefulShellRoute.indexedStack(
        builder: (context, state, navigationShell) =>
            AppShell(navigationShell: navigationShell),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/home',
                builder: (context, state) => const HomePage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/tasks',
                builder: (context, state) => const TasksPlaceholderPage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/schedule',
                builder: (context, state) => const SchedulePlaceholderPage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/messages',
                builder: (context, state) => const MessagesPlaceholderPage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/more',
                builder: (context, state) => const MorePlaceholderPage(),
              ),
            ],
          ),
        ],
      ),
    ],
  );
}

/// Unauthenticated -> `/login`; authenticated -> the shell (`/home` by
/// default); [AuthStatus.unknown] (bootstrap still resolving the stored
/// token) -> `/splash`, the router-level equivalent of [AuthGate]'s old
/// `CircularProgressIndicator` Scaffold. Each branch only redirects when
/// not already at the target location, so a settled state produces no
/// further redirect — the loop-prevention this router relies on.
String? _redirect(AuthController authController, GoRouterState state) {
  final status = authController.status;
  final location = state.matchedLocation;

  if (status == AuthStatus.unknown) {
    return location == '/splash' ? null : '/splash';
  }

  final authenticated = status == AuthStatus.authenticated;

  if (!authenticated) {
    return location == '/login' ? null : '/login';
  }

  if (location == '/login' || location == '/splash') {
    return '/home';
  }

  return null;
}

class _SplashPage extends StatelessWidget {
  const _SplashPage();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(body: Center(child: CircularProgressIndicator()));
  }
}

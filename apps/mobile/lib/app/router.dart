import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/data/home_api_client.dart';
import 'package:mobile/features/home/presentation/home_page.dart';
import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/people/presentation/more_page.dart';
import 'package:mobile/features/people/presentation/my_profile_page.dart';
import 'package:mobile/features/people/presentation/staff_detail_page.dart';
import 'package:mobile/features/people/presentation/staff_directory_page.dart';
import 'package:mobile/features/shell/presentation/app_shell.dart';
import 'package:mobile/features/shell/presentation/placeholder_page.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/presentation/task_detail_page.dart';
import 'package:mobile/features/tasks/presentation/task_widgets.dart';
import 'package:mobile/features/tasks/presentation/tasks_page.dart';
import 'package:mobile/features/tasks/state/task_changes.dart';

/// Builds the app's single [GoRouter] — the sole navigation mechanism
/// (Phase 25, DEC-046), replacing the former [AuthGate] widget-switch with
/// declarative, route-level auth redirects. [authController] is also
/// wired in as `refreshListenable` so every status change (bootstrap
/// resolving, login, logout) re-evaluates [_redirect] automatically.
GoRouter buildAppRouter(
  AuthController authController, {
  required HomeApiClient homeApiClient,
  required PeopleApiClient peopleApiClient,
  required TasksApiClient tasksApiClient,
  required TaskChanges taskChanges,
}) {
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
                builder: (context, state) => HomePage(
                  homeApiClient: homeApiClient,
                  taskChanges: taskChanges,
                ),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              // Tasks (Phase 29A): the detail stays inside the Tasks branch,
              // so Home's task links switch to this tab (spec §5.3).
              GoRoute(
                path: '/tasks',
                builder: (context, state) => TasksPage(
                  tasksApiClient: tasksApiClient,
                  taskChanges: taskChanges,
                ),
                routes: [
                  GoRoute(
                    path: ':publicId',
                    builder: (context, state) => TaskDetailPage(
                      key: ValueKey(state.pathParameters['publicId']),
                      tasksApiClient: tasksApiClient,
                      taskChanges: taskChanges,
                      publicId: state.pathParameters['publicId']!,
                      args: state.extra is TaskDetailArgs
                          ? state.extra! as TaskDetailArgs
                          : null,
                    ),
                  ),
                ],
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
              // People (Phase 28): every sub-page stays inside the More
              // branch, so the tab keeps its own stack across tab switches.
              GoRoute(
                path: '/more',
                builder: (context, state) => const MorePage(),
                routes: [
                  GoRoute(
                    path: 'profile',
                    builder: (context, state) =>
                        MyProfilePage(peopleApiClient: peopleApiClient),
                  ),
                  GoRoute(
                    path: 'directory',
                    builder: (context, state) =>
                        StaffDirectoryPage(peopleApiClient: peopleApiClient),
                    routes: [
                      GoRoute(
                        path: ':publicId',
                        builder: (context, state) => StaffDetailPage(
                          // A new key per person, so opening a manager
                          // from a detail page builds a fresh page.
                          key: ValueKey(state.pathParameters['publicId']),
                          peopleApiClient: peopleApiClient,
                          publicId: state.pathParameters['publicId']!,
                        ),
                      ),
                    ],
                  ),
                ],
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

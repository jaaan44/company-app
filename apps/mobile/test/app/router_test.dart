import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/home_page.dart';
import 'package:mobile/features/shell/presentation/placeholder_page.dart';

import '../features/auth/fake_token_storage.dart';

const _userJson = {
  'public_id': '01ARZ3NDEKTSV4RRFFQ69G5FAV',
  'name': 'Ada Lovelace',
  'email': 'ada@example.com',
  'status': 'active',
};

AuthController _authenticatedController() {
  return AuthController(
    apiClient: AuthApiClient(
      httpClient: MockClient(
        (request) async => http.Response(jsonEncode({'data': _userJson}), 200),
      ),
    ),
    tokenStorage: FakeTokenStorage(initialToken: 'stored-token'),
  );
}

Future<void> _pumpAuthenticatedShell(WidgetTester tester) async {
  await tester.pumpWidget(
    CompanyApp(authController: _authenticatedController()),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('unauthenticated launch redirects to the login screen', (
    tester,
  ) async {
    final controller = AuthController(
      apiClient: AuthApiClient(
        httpClient: MockClient((request) async {
          fail('should not call the API when there is no stored token');
        }),
      ),
      tokenStorage: FakeTokenStorage(),
    );

    await tester.pumpWidget(CompanyApp(authController: controller));
    await tester.pumpAndSettle();

    expect(find.byType(LoginPage), findsOneWidget);
  });

  testWidgets(
    'a valid stored token restores directly into the shell with no login flash',
    (tester) async {
      final completer = Completer<http.Response>();
      final controller = AuthController(
        apiClient: AuthApiClient(
          httpClient: MockClient((request) => completer.future),
        ),
        tokenStorage: FakeTokenStorage(initialToken: 'stored-token'),
      );

      await tester.pumpWidget(CompanyApp(authController: controller));
      await tester.pump();

      // Bootstrap hasn't resolved yet — neither screen has appeared.
      expect(find.byType(LoginPage), findsNothing);
      expect(find.byType(HomePage), findsNothing);
      expect(find.byType(CircularProgressIndicator), findsOneWidget);

      completer.complete(http.Response(jsonEncode({'data': _userJson}), 200));
      await tester.pumpAndSettle();

      expect(find.byType(LoginPage), findsNothing);
      expect(find.byType(HomePage), findsOneWidget);
      expect(find.text('Signed in as Ada Lovelace'), findsOneWidget);
    },
  );

  testWidgets('successful login lands in the shell on the Home tab', (
    tester,
  ) async {
    final storage = FakeTokenStorage();
    final controller = AuthController(
      apiClient: AuthApiClient(
        httpClient: MockClient((request) async {
          return http.Response(
            jsonEncode({
              'data': {'user': _userJson, 'token': 'new-token'},
            }),
            200,
          );
        }),
      ),
      tokenStorage: storage,
    );

    await tester.pumpWidget(CompanyApp(authController: controller));
    await tester.pumpAndSettle();

    expect(find.byType(LoginPage), findsOneWidget);

    await tester.enterText(find.byType(TextFormField).first, 'ada@example.com');
    await tester.enterText(find.byType(TextFormField).last, 'password');
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pumpAndSettle();

    expect(find.byType(HomePage), findsOneWidget);
    expect(await storage.readToken(), 'new-token');
  });

  testWidgets('logging out returns to the login screen and clears the token', (
    tester,
  ) async {
    final storage = FakeTokenStorage(initialToken: 'stored-token');
    final controller = AuthController(
      apiClient: AuthApiClient(
        httpClient: MockClient((request) async {
          if (request.url.path.endsWith('/auth/me')) {
            return http.Response(jsonEncode({'data': _userJson}), 200);
          }

          return http.Response(
            jsonEncode({
              'data': {'message': 'Logged out successfully.'},
            }),
            200,
          );
        }),
      ),
      tokenStorage: storage,
    );

    await tester.pumpWidget(CompanyApp(authController: controller));
    await tester.pumpAndSettle();

    expect(find.byType(HomePage), findsOneWidget);

    await tester.tap(find.byIcon(Icons.logout));
    await tester.pumpAndSettle();

    expect(find.byType(LoginPage), findsOneWidget);
    expect(await storage.readToken(), isNull);
  });

  testWidgets(
    'an authenticated user is bounced straight back from /login (redirect-loop prevention)',
    (tester) async {
      await _pumpAuthenticatedShell(tester);

      expect(find.byType(HomePage), findsOneWidget);

      GoRouter.of(tester.element(find.byType(HomePage))).go('/login');
      await tester.pumpAndSettle();

      expect(find.byType(LoginPage), findsNothing);
      expect(find.byType(HomePage), findsOneWidget);
    },
  );

  group('bottom-navigation shell', () {
    testWidgets('every destination is reachable and renders its content', (
      tester,
    ) async {
      await _pumpAuthenticatedShell(tester);

      expect(find.byType(HomePage), findsOneWidget);

      await tester.tap(find.widgetWithText(NavigationDestination, 'Tasks'));
      await tester.pumpAndSettle();
      expect(find.byType(TasksPlaceholderPage), findsOneWidget);
      expect(find.text('Tasks — coming soon'), findsOneWidget);

      await tester.tap(find.widgetWithText(NavigationDestination, 'Schedule'));
      await tester.pumpAndSettle();
      expect(find.byType(SchedulePlaceholderPage), findsOneWidget);
      expect(find.text('Schedule — coming soon'), findsOneWidget);

      await tester.tap(find.widgetWithText(NavigationDestination, 'Messages'));
      await tester.pumpAndSettle();
      expect(find.byType(MessagesPlaceholderPage), findsOneWidget);
      expect(find.text('Messages — coming soon'), findsOneWidget);

      await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
      await tester.pumpAndSettle();
      expect(find.byType(MorePlaceholderPage), findsOneWidget);
      expect(find.text('More — coming soon'), findsOneWidget);

      await tester.tap(find.widgetWithText(NavigationDestination, 'Home'));
      await tester.pumpAndSettle();
      expect(find.byType(HomePage), findsOneWidget);
    });

    testWidgets('the active tab is indicated in the NavigationBar', (
      tester,
    ) async {
      await _pumpAuthenticatedShell(tester);

      var navigationBar = tester.widget<NavigationBar>(
        find.byType(NavigationBar),
      );
      expect(navigationBar.selectedIndex, 0);

      await tester.tap(find.widgetWithText(NavigationDestination, 'Tasks'));
      await tester.pumpAndSettle();

      navigationBar = tester.widget<NavigationBar>(find.byType(NavigationBar));
      expect(navigationBar.selectedIndex, 1);
    });

    testWidgets(
      'switching away from a tab keeps its branch mounted instead of disposing it',
      (tester) async {
        await _pumpAuthenticatedShell(tester);

        expect(find.byType(HomePage), findsOneWidget);

        await tester.tap(find.widgetWithText(NavigationDestination, 'Tasks'));
        await tester.pumpAndSettle();

        // StatefulShellRoute.indexedStack keeps every branch's Navigator
        // alive off-screen (rather than rebuilding it from scratch) so
        // each tab preserves its own navigation state across switches —
        // the concrete guarantee this shell exists to provide.
        expect(find.byType(HomePage, skipOffstage: false), findsOneWidget);

        await tester.tap(find.widgetWithText(NavigationDestination, 'Home'));
        await tester.pumpAndSettle();

        expect(find.byType(HomePage), findsOneWidget);
      },
    );
  });

  group('theme', () {
    testWidgets('light theme is applied by default', (tester) async {
      await _pumpAuthenticatedShell(tester);

      final context = tester.element(find.byType(HomePage));
      expect(Theme.of(context).brightness, Brightness.light);
    });

    testWidgets('dark theme is applied when the device requests dark mode', (
      tester,
    ) async {
      tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);

      await _pumpAuthenticatedShell(tester);

      final context = tester.element(find.byType(HomePage));
      expect(Theme.of(context).brightness, Brightness.dark);
    });
  });
}

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/app/app.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/presentation/home_page.dart';

import '../support/fake_backend.dart';

/// Phase 27 Gate 2 — mid-session authentication expiry through the real
/// app: [CompanyApp], its router and bottom-navigation shell, a real
/// [AuthController], and a real [ApiClient] request (standing in for the
/// Home request Gate 3 adds). See docs/phases/V1_PHASE_27_DEFINITION.md §7.
void main() {
  late FakeBackend backend;
  late RecordingTokenStorage storage;
  late AuthController auth;
  late ApiClient api;

  Future<void> pumpSignedInApp(WidgetTester tester) async {
    backend = FakeBackend();
    storage = RecordingTokenStorage(initialToken: 'token-a');
    auth = backend.controller(storage);
    api = backend.apiClient(auth);

    await tester.pumpWidget(CompanyApp(authController: auth));
    await tester.pumpAndSettle();
    expect(find.byType(HomePage), findsOneWidget);
    expect(find.byType(NavigationBar), findsOneWidget);
  }

  void expectLoginWithNotice() {
    expect(find.byType(LoginPage), findsOneWidget);
    expect(find.text(AuthController.sessionEndedNotice), findsOneWidget);
    // The authenticated shell (every branch, on- or off-stage) is gone.
    expect(find.byType(HomePage, skipOffstage: false), findsNothing);
    expect(find.byType(NavigationBar), findsNothing);
  }

  testWidgets('a 401 returns to Login with the session-ended notice', (
    tester,
  ) async {
    await pumpSignedInApp(tester);
    backend.onApi = (request) async => jsonError(401, 'Unauthenticated.');

    await expectLater(
      api.getJson('/me/home'),
      throwsA(isA<ApiSessionExpiredException>()),
    );
    await tester.pumpAndSettle();

    expectLoginWithNotice();
    expect(await storage.readToken(), isNull);
    expect(backend.logoutCalls, 0);
  });

  testWidgets('403 + /auth/me 200 keeps the shell open with no notice', (
    tester,
  ) async {
    await pumpSignedInApp(tester);
    backend.onApi = (request) async => jsonError(403, 'Not allowed here.');

    await expectLater(
      api.getJson('/me/home'),
      throwsA(isA<ApiForbiddenException>()),
    );
    await tester.pumpAndSettle();

    expect(find.byType(HomePage), findsOneWidget);
    expect(find.byType(LoginPage), findsNothing);
    expect(find.text(AuthController.sessionEndedNotice), findsNothing);
    expect(await storage.readToken(), 'token-a');
  });

  testWidgets('403 + /auth/me 401 returns to Login with the notice', (
    tester,
  ) async {
    await pumpSignedInApp(tester);
    backend.onApi = (request) async =>
        jsonError(403, 'This account is not currently active.');
    backend.meStatus = 401;

    await expectLater(
      api.getJson('/me/home'),
      throwsA(isA<ApiSessionExpiredException>()),
    );
    await tester.pumpAndSettle();

    expectLoginWithNotice();
    expect(backend.logoutCalls, 0);
  });

  testWidgets('403 + inconclusive /auth/me (5xx) keeps the session', (
    tester,
  ) async {
    await pumpSignedInApp(tester);
    backend.onApi = (request) async => jsonError(403);
    backend.meStatus = 500;

    await expectLater(
      api.getJson('/me/home'),
      throwsA(isA<ApiForbiddenException>()),
    );
    await tester.pumpAndSettle();

    expect(find.byType(HomePage), findsOneWidget);
    expect(find.text(AuthController.sessionEndedNotice), findsNothing);
  });

  testWidgets('an unrelated network or server failure never shows the notice', (
    tester,
  ) async {
    await pumpSignedInApp(tester);

    backend.onApi = (request) async => throw const NetworkFailure();
    await expectLater(
      api.getJson('/me/home'),
      throwsA(isA<ApiNetworkException>()),
    );
    backend.onApi = (request) async => jsonError(500);
    await expectLater(
      api.getJson('/me/home'),
      throwsA(isA<ApiServerException>()),
    );
    await tester.pumpAndSettle();

    expect(find.byType(HomePage), findsOneWidget);
    expect(find.text(AuthController.sessionEndedNotice), findsNothing);
  });

  testWidgets('manual logout returns to Login without the notice', (
    tester,
  ) async {
    await pumpSignedInApp(tester);

    await tester.tap(find.byIcon(Icons.logout));
    await tester.pumpAndSettle();

    expect(find.byType(LoginPage), findsOneWidget);
    expect(find.text(AuthController.sessionEndedNotice), findsNothing);
    expect(backend.logoutCalls, 1);
    expect(await storage.readToken(), isNull);
  });

  testWidgets('a fresh launch with no stored token shows no notice', (
    tester,
  ) async {
    final fresh = FakeBackend();
    await tester.pumpWidget(
      CompanyApp(authController: fresh.controller(RecordingTokenStorage())),
    );
    await tester.pumpAndSettle();

    expect(find.byType(LoginPage), findsOneWidget);
    expect(find.text(AuthController.sessionEndedNotice), findsNothing);
  });

  testWidgets(
    'signing in again after expiry clears the notice and restores the shell',
    (tester) async {
      await pumpSignedInApp(tester);
      backend.onApi = (request) async => jsonError(401);
      await expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      await tester.pumpAndSettle();
      expectLoginWithNotice();

      backend.nextLoginToken = 'token-b';
      await tester.enterText(
        find.byType(TextFormField).first,
        'ada@example.com',
      );
      await tester.enterText(find.byType(TextFormField).last, 'password');
      await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
      await tester.pumpAndSettle();

      expect(find.byType(HomePage), findsOneWidget);
      expect(find.text(AuthController.sessionEndedNotice), findsNothing);
      expect(auth.currentToken, 'token-b');
      expect(await storage.readToken(), 'token-b');
    },
  );

  testWidgets(
    'a stale token-A 401 after re-login leaves the new session in the shell',
    (tester) async {
      await pumpSignedInApp(tester);

      // Manual logout, then sign in again as token B.
      await tester.tap(find.byIcon(Icons.logout));
      await tester.pumpAndSettle();
      backend.nextLoginToken = 'token-b';
      await tester.enterText(
        find.byType(TextFormField).first,
        'ada@example.com',
      );
      await tester.enterText(find.byType(TextFormField).last, 'password');
      await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
      await tester.pumpAndSettle();
      expect(find.byType(HomePage), findsOneWidget);

      // A late 401 for token A is reported to the session owner.
      await auth.expireSession(tokenUsed: 'token-a');
      await tester.pumpAndSettle();

      expect(find.byType(HomePage), findsOneWidget);
      expect(find.byType(LoginPage), findsNothing);
      expect(auth.currentToken, 'token-b');
    },
  );
}

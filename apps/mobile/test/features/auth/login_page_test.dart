import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';

import '../../support/fake_backend.dart';
import 'fake_token_storage.dart';

Widget _wrap(Widget child) => MaterialApp(home: child);

void main() {
  testWidgets('shows validation errors when submitted empty', (tester) async {
    final controller = AuthController(
      apiClient: AuthApiClient(
        httpClient: MockClient((request) async {
          fail('should not call the API for a client-side validation failure');
        }),
      ),
      tokenStorage: FakeTokenStorage(),
    );

    await tester.pumpWidget(_wrap(LoginPage(controller: controller)));
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pump();

    expect(find.text('Email is required'), findsOneWidget);
    expect(find.text('Password is required'), findsOneWidget);
  });

  testWidgets('shows a loading indicator while the request is in flight', (
    tester,
  ) async {
    final completer = Completer<http.Response>();
    final controller = AuthController(
      apiClient: AuthApiClient(
        httpClient: MockClient((request) => completer.future),
      ),
      tokenStorage: FakeTokenStorage(),
    );

    await tester.pumpWidget(_wrap(LoginPage(controller: controller)));

    await tester.enterText(find.byType(TextFormField).first, 'ada@example.com');
    await tester.enterText(find.byType(TextFormField).last, 'password');
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pump();

    expect(find.byType(CircularProgressIndicator), findsOneWidget);

    completer.complete(
      http.Response(
        jsonEncode({
          'data': {
            'user': {
              'public_id': '01ARZ3NDEKTSV4RRFFQ69G5FAV',
              'name': 'Ada',
              'email': 'ada@example.com',
              'status': 'active',
            },
            'token': 'a-token',
          },
        }),
        200,
      ),
    );
    await tester.pumpAndSettle();
  });

  testWidgets('displays the server error message on invalid credentials', (
    tester,
  ) async {
    final controller = AuthController(
      apiClient: AuthApiClient(
        httpClient: MockClient((request) async {
          return http.Response(
            jsonEncode({
              'errors': {
                'email': ['These credentials do not match our records.'],
              },
            }),
            422,
          );
        }),
      ),
      tokenStorage: FakeTokenStorage(),
    );

    await tester.pumpWidget(_wrap(LoginPage(controller: controller)));

    await tester.enterText(find.byType(TextFormField).first, 'ada@example.com');
    await tester.enterText(find.byType(TextFormField).last, 'wrong-password');
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pumpAndSettle();

    expect(
      find.text('These credentials do not match our records.'),
      findsOneWidget,
    );
    expect(controller.status, AuthStatus.unauthenticated);
  });

  group('session-ended notice (Phase 27)', () {
    Future<AuthController> expiredController(FakeBackend backend) async {
      final controller = backend.controller(
        RecordingTokenStorage(initialToken: 'token-a'),
      );
      await controller.bootstrap();
      await controller.expireSession(tokenUsed: 'token-a');

      return controller;
    }

    testWidgets('is shown, and announced, after an automatic expiry', (
      tester,
    ) async {
      final controller = await expiredController(FakeBackend());

      await tester.pumpWidget(_wrap(LoginPage(controller: controller)));

      final notice = find.text(AuthController.sessionEndedNotice);
      expect(notice, findsOneWidget);
      expect(
        find.ancestor(
          of: notice,
          matching: find.byWidgetPredicate(
            (widget) =>
                widget is Semantics && widget.properties.liveRegion == true,
          ),
        ),
        findsOneWidget,
      );
    });

    testWidgets('disappears as soon as the next sign-in is submitted', (
      tester,
    ) async {
      final backend = FakeBackend()..nextLoginToken = 'token-b';
      final controller = await expiredController(backend);

      await tester.pumpWidget(_wrap(LoginPage(controller: controller)));
      await tester.enterText(
        find.byType(TextFormField).first,
        'ada@example.com',
      );
      await tester.enterText(find.byType(TextFormField).last, 'password');
      await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
      await tester.pump();

      expect(find.text(AuthController.sessionEndedNotice), findsNothing);
      await tester.pumpAndSettle();
      expect(controller.sessionEndedMessage, isNull);
    });

    testWidgets('is not shown after a manual logout', (tester) async {
      final backend = FakeBackend();
      final controller = backend.controller(
        RecordingTokenStorage(initialToken: 'token-a'),
      );
      await controller.bootstrap();
      await controller.logout();

      await tester.pumpWidget(_wrap(LoginPage(controller: controller)));

      expect(find.text(AuthController.sessionEndedNotice), findsNothing);
    });
  });
}

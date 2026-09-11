import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/presentation/auth_gate.dart';
import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/home_page.dart';

import 'fake_token_storage.dart';

Widget _wrap(Widget child) => MaterialApp(home: child);

void main() {
  testWidgets(
    'shows a loading indicator while the stored token is being checked',
    (tester) async {
      final completer = Completer<http.Response>();
      final controller = AuthController(
        apiClient: AuthApiClient(
          httpClient: MockClient((request) => completer.future),
        ),
        tokenStorage: FakeTokenStorage(initialToken: 'stored-token'),
      );

      await tester.pumpWidget(_wrap(AuthGate(controller: controller)));
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsOneWidget);

      completer.complete(
        http.Response(
          jsonEncode({
            'data': {
              'public_id': '01ARZ3NDEKTSV4RRFFQ69G5FAV',
              'name': 'Ada',
              'email': 'ada@example.com',
              'status': 'active',
            },
          }),
          200,
        ),
      );
      await tester.pumpAndSettle();
    },
  );

  testWidgets('no stored token shows the login screen', (tester) async {
    final controller = AuthController(
      apiClient: AuthApiClient(
        httpClient: MockClient((request) async {
          fail('should not call the API when there is no stored token');
        }),
      ),
      tokenStorage: FakeTokenStorage(),
    );

    await tester.pumpWidget(_wrap(AuthGate(controller: controller)));
    await tester.pumpAndSettle();

    expect(find.byType(LoginPage), findsOneWidget);
  });

  testWidgets(
    'a valid stored token restores straight into the authenticated shell',
    (tester) async {
      final controller = AuthController(
        apiClient: AuthApiClient(
          httpClient: MockClient((request) async {
            return http.Response(
              jsonEncode({
                'data': {
                  'public_id': '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                  'name': 'Ada Lovelace',
                  'email': 'ada@example.com',
                  'status': 'active',
                },
              }),
              200,
            );
          }),
        ),
        tokenStorage: FakeTokenStorage(initialToken: 'stored-token'),
      );

      await tester.pumpWidget(_wrap(AuthGate(controller: controller)));
      await tester.pumpAndSettle();

      expect(find.byType(HomePage), findsOneWidget);
      expect(find.text('Signed in as Ada Lovelace'), findsOneWidget);
    },
  );

  testWidgets(
    'successful login transitions from the login screen to the home shell',
    (tester) async {
      final storage = FakeTokenStorage();
      final controller = AuthController(
        apiClient: AuthApiClient(
          httpClient: MockClient((request) async {
            return http.Response(
              jsonEncode({
                'data': {
                  'user': {
                    'public_id': '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                    'name': 'Ada Lovelace',
                    'email': 'ada@example.com',
                    'status': 'active',
                  },
                  'token': 'new-token',
                },
              }),
              200,
            );
          }),
        ),
        tokenStorage: storage,
      );

      await tester.pumpWidget(_wrap(AuthGate(controller: controller)));
      await tester.pumpAndSettle();

      expect(find.byType(LoginPage), findsOneWidget);

      await tester.enterText(
        find.byType(TextFormField).first,
        'ada@example.com',
      );
      await tester.enterText(find.byType(TextFormField).last, 'password');
      await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
      await tester.pumpAndSettle();

      expect(find.byType(HomePage), findsOneWidget);
      expect(await storage.readToken(), 'new-token');
    },
  );

  testWidgets('logging out returns to the login screen and clears the token', (
    tester,
  ) async {
    final storage = FakeTokenStorage(initialToken: 'stored-token');
    final controller = AuthController(
      apiClient: AuthApiClient(
        httpClient: MockClient((request) async {
          if (request.url.path.endsWith('/auth/me')) {
            return http.Response(
              jsonEncode({
                'data': {
                  'public_id': '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                  'name': 'Ada Lovelace',
                  'email': 'ada@example.com',
                  'status': 'active',
                },
              }),
              200,
            );
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

    await tester.pumpWidget(_wrap(AuthGate(controller: controller)));
    await tester.pumpAndSettle();

    expect(find.byType(HomePage), findsOneWidget);

    await tester.tap(find.byIcon(Icons.logout));
    await tester.pumpAndSettle();

    expect(find.byType(LoginPage), findsOneWidget);
    expect(await storage.readToken(), isNull);
  });
}

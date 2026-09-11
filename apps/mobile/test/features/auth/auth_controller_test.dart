import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';

import 'fake_token_storage.dart';

const _userJson = {
  'public_id': '01ARZ3NDEKTSV4RRFFQ69G5FAV',
  'name': 'Ada Lovelace',
  'email': 'ada@example.com',
  'status': 'active',
};

void main() {
  group('bootstrap', () {
    test('no stored token leaves the controller unauthenticated', () async {
      final controller = AuthController(
        apiClient: AuthApiClient(
          httpClient: MockClient((request) async {
            fail('should not call the API when there is no stored token');
          }),
        ),
        tokenStorage: FakeTokenStorage(),
      );

      await controller.bootstrap();

      expect(controller.status, AuthStatus.unauthenticated);
      expect(controller.user, isNull);
    });

    test('a valid stored token restores the authenticated user', () async {
      final client = MockClient((request) async {
        expect(request.url.path, endsWith('/auth/me'));
        expect(request.headers['Authorization'], 'Bearer stored-token');

        return http.Response(jsonEncode({'data': _userJson}), 200);
      });

      final controller = AuthController(
        apiClient: AuthApiClient(httpClient: client),
        tokenStorage: FakeTokenStorage(initialToken: 'stored-token'),
      );

      await controller.bootstrap();

      expect(controller.status, AuthStatus.authenticated);
      expect(controller.user?.name, 'Ada Lovelace');
    });

    test(
      'an invalid stored token is cleared and falls back to login',
      () async {
        final storage = FakeTokenStorage(initialToken: 'expired-token');
        final controller = AuthController(
          apiClient: AuthApiClient(
            httpClient: MockClient((request) async {
              return http.Response(
                jsonEncode({'message': 'Unauthenticated.'}),
                401,
              );
            }),
          ),
          tokenStorage: storage,
        );

        await controller.bootstrap();

        expect(controller.status, AuthStatus.unauthenticated);
        expect(await storage.readToken(), isNull);
      },
    );
  });

  group('login', () {
    test('valid credentials authenticate and persist the token', () async {
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

      final success = await controller.login('ada@example.com', 'password');

      expect(success, isTrue);
      expect(controller.status, AuthStatus.authenticated);
      expect(controller.user?.email, 'ada@example.com');
      expect(await storage.readToken(), 'new-token');
    });

    test(
      'invalid credentials remain unauthenticated with an error message',
      () async {
        final controller = AuthController(
          apiClient: AuthApiClient(
            httpClient: MockClient((request) async {
              return http.Response(
                jsonEncode({
                  'message': 'The given data was invalid.',
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

        final success = await controller.login('ada@example.com', 'wrong');

        expect(success, isFalse);
        expect(controller.status, AuthStatus.unauthenticated);
        expect(
          controller.errorMessage,
          'These credentials do not match our records.',
        );
        expect(controller.user, isNull);
      },
    );

    test(
      'a network failure surfaces a friendly error and stays logged out',
      () async {
        final controller = AuthController(
          apiClient: AuthApiClient(
            httpClient: MockClient((request) async {
              throw const SocketExceptionStub();
            }),
          ),
          tokenStorage: FakeTokenStorage(),
        );

        final success = await controller.login('ada@example.com', 'password');

        expect(success, isFalse);
        expect(controller.status, AuthStatus.unauthenticated);
        expect(controller.errorMessage, isNotNull);
      },
    );
  });

  group('logout', () {
    test('clears the token and returns to unauthenticated', () async {
      final storage = FakeTokenStorage(initialToken: 'some-token');
      final controller = AuthController(
        apiClient: AuthApiClient(
          httpClient: MockClient((request) async {
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

      await controller.logout();

      expect(controller.status, AuthStatus.unauthenticated);
      expect(controller.user, isNull);
      expect(await storage.readToken(), isNull);
    });

    test(
      'still clears the local token even if the server call fails',
      () async {
        final storage = FakeTokenStorage(initialToken: 'some-token');
        final controller = AuthController(
          apiClient: AuthApiClient(
            httpClient: MockClient((request) async {
              return http.Response(
                jsonEncode({'message': 'Unauthenticated.'}),
                401,
              );
            }),
          ),
          tokenStorage: storage,
        );

        await controller.logout();

        expect(controller.status, AuthStatus.unauthenticated);
        expect(await storage.readToken(), isNull);
      },
    );
  });
}

/// A minimal stand-in exception implementing [Exception] so [AuthApiClient]
/// treats it as a network failure without depending on `dart:io`'s
/// `SocketException` (unavailable in some test environments).
class SocketExceptionStub implements Exception {
  const SocketExceptionStub();
}

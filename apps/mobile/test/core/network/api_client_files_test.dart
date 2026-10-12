import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';

import '../../support/fake_backend.dart';

/// Phase 33 (R-9): [ApiClient.postMultipart] and [ApiClient.getBytes]
/// follow every shared session rule — token, 401, 403 re-check, 422, 5xx,
/// network — and send once.
void main() {
  late FakeBackend backend;
  late AuthController session;

  setUp(() async {
    backend = FakeBackend();
    session = backend.controller(
      RecordingTokenStorage(initialToken: 'token-a'),
    );
    await session.bootstrap();
  });

  group('postMultipart', () {
    test(
      'one file part with its name, the token, and the JSON answer',
      () async {
        http.Request? seen;
        backend.onApi = (r) async {
          seen = r;

          return http.Response(
            jsonEncode({
              'data': {'public_id': 'F1'},
            }),
            201,
          );
        };

        final body = await backend
            .apiClient(session)
            .postMultipart(
              '/service-reports/R1/attachments',
              field: 'file',
              bytes: [0xFF, 0xD8, 0xFF, 0x01],
              filename: 'photo.jpg',
            );

        expect(body, {
          'data': {'public_id': 'F1'},
        });
        expect(seen!.method, 'POST');
        expect(
          seen!.url.toString(),
          '$testBaseUrl/service-reports/R1/attachments',
        );
        expect(seen!.headers['Authorization'], 'Bearer token-a');
        expect(seen!.headers['Accept'], 'application/json');
        expect(
          seen!.headers['content-type'],
          startsWith('multipart/form-data; boundary='),
        );
        final raw = latin1.decode(seen!.bodyBytes);
        expect(raw, contains('name="file"; filename="photo.jpg"'));
        expect(seen!.bodyBytes, containsAllInOrder([0xFF, 0xD8, 0xFF, 0x01]));
      },
    );

    test('a 422 is a validation error with the field', () async {
      var calls = 0;
      backend.onApi = (r) async {
        calls++;

        return http.Response(
          jsonEncode({
            'message':
                'The file field must be a file of type: jpg, jpeg, png, pdf.',
            'errors': {
              'file': [
                'The file field must be a file of type: jpg, jpeg, png, pdf.',
              ],
            },
          }),
          422,
        );
      };

      await expectLater(
        backend
            .apiClient(session)
            .postMultipart('/x', field: 'file', bytes: [1], filename: 'a.gif'),
        throwsA(
          isA<ApiValidationException>().having(
            (e) => e.firstErrorFor('file'),
            'file',
            contains('jpg'),
          ),
        ),
      );
      expect(calls, 1);
    });

    test('a 401 ends the session', () async {
      backend.onApi = (r) async => http.Response('{}', 401);

      await expectLater(
        backend
            .apiClient(session)
            .postMultipart('/x', field: 'file', bytes: [1], filename: 'a.jpg'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      expect(session.status, AuthStatus.unauthenticated);
    });

    test('a 403 re-checks once and keeps the session', () async {
      final meBefore = backend.meCalls;
      var posts = 0;
      backend.onApi = (r) async {
        posts++;

        return http.Response(jsonEncode({'message': 'Denied.'}), 403);
      };

      await expectLater(
        backend
            .apiClient(session)
            .postMultipart('/x', field: 'file', bytes: [1], filename: 'a.jpg'),
        throwsA(
          isA<ApiForbiddenException>().having(
            (e) => e.message,
            'message',
            'Denied.',
          ),
        ),
      );
      expect(backend.meCalls, meBefore + 1);
      expect(posts, 1);
      expect(session.status, AuthStatus.authenticated);
    });

    test('offline is a network error, sent once', () async {
      var calls = 0;
      backend.onApi = (r) async {
        calls++;
        throw const NetworkFailure();
      };

      await expectLater(
        backend
            .apiClient(session)
            .postMultipart('/x', field: 'file', bytes: [1], filename: 'a.jpg'),
        throwsA(isA<ApiNetworkException>()),
      );
      expect(calls, 1);
    });
  });

  group('getBytes', () {
    test('returns the raw bytes, asks for any type', () async {
      http.Request? seen;
      backend.onApi = (r) async {
        seen = r;

        return http.Response.bytes(
          [1, 2, 3, 250],
          200,
          headers: {'content-type': 'image/jpeg'},
        );
      };

      final bytes = await backend.apiClient(session).getBytes('/a/download');

      expect(bytes, [1, 2, 3, 250]);
      expect(seen!.headers['Accept'], '*/*');
      expect(seen!.headers['Authorization'], 'Bearer token-a');
    });

    test('a 404 and a 500 are errors, not bytes', () async {
      backend.onApi = (r) async =>
          http.Response(jsonEncode({'message': 'Not found.'}), 404);
      await expectLater(
        backend.apiClient(session).getBytes('/a'),
        throwsA(
          isA<ApiRequestException>().having((e) => e.statusCode, 'status', 404),
        ),
      );

      backend.onApi = (r) async => http.Response('oops', 500);
      await expectLater(
        backend.apiClient(session).getBytes('/a'),
        throwsA(isA<ApiServerException>()),
      );
    });
  });
}

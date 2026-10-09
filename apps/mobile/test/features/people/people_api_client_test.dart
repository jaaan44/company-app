import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/people/data/people_api_client.dart';

import '../../support/fake_backend.dart';
import '../../support/people_fixtures.dart';

/// Phase 28 Gate 2 — [PeopleApiClient] over the real Phase 27 `ApiClient`
/// (spec §7): exact paths and query encoding, the active-only directory
/// (R-1), contract failures, and the unchanged 401/403 session rule.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late PeopleApiClient people;
  late List<Uri> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
    people = peopleClientFor(backend, auth);
  });

  void answer(http.Response Function(http.Request) respond) {
    backend.onApi = (request) async {
      requests.add(request.url);

      return respond(request);
    };
  }

  group('fetchMyProfile', () {
    test('GETs /me/profile with the bearer token and parses it', () async {
      String? authHeader;
      backend.onApi = (request) async {
        requests.add(request.url);
        authHeader = request.headers['Authorization'];

        return jsonResponse({'data': profileDataJson()});
      };

      final profile = await people.fetchMyProfile();

      expect(requests.single.path, '/api/v1/me/profile');
      expect(requests.single.query, isEmpty);
      expect(authHeader, 'Bearer token-a');
      expect(profile.staff!.displayName, 'Ada Lovelace');
    });

    test('a no-profile response parses to staff == null', () async {
      answer((_) => jsonResponse({'data': profileDataJson(staff: null)}));

      expect((await people.fetchMyProfile()).hasStaffProfile, isFalse);
    });

    test('an unexpected shape is an ApiRequestException, not a crash', () {
      answer((_) => jsonResponse({'data': 'nope'}));

      expect(people.fetchMyProfile(), throwsA(isA<ApiRequestException>()));
    });
  });

  group('fetchDirectoryPage', () {
    test('always asks for active staff only, 25 per page (R-1)', () async {
      answer((_) => jsonResponse(directoryPageJson(staffList(1))));

      await people.fetchDirectoryPage();

      final url = requests.single;
      expect(url.path, '/api/v1/staff');
      expect(url.queryParameters, {
        'status': 'active',
        'per_page': '25',
        'page': '1',
      });
    });

    test('sends the requested page', () async {
      answer((_) => jsonResponse(directoryPageJson([], page: 3, lastPage: 3)));

      await people.fetchDirectoryPage(page: 3);

      expect(requests.single.queryParameters['page'], '3');
    });

    test('an empty or whitespace query is not sent', () async {
      answer((_) => jsonResponse(directoryPageJson([])));

      await people.fetchDirectoryPage(query: '');
      await people.fetchDirectoryPage(query: '   ');

      for (final url in requests) {
        expect(url.queryParameters.containsKey('q'), isFalse);
      }
    });

    test('the search text is trimmed and fully encoded', () async {
      answer((_) => jsonResponse(directoryPageJson([])));

      await people.fetchDirectoryPage(query: '  José & Ana?page=9#x  ');

      final url = requests.single;
      // Decoded back exactly — nothing leaked into other parameters.
      expect(url.queryParameters['q'], 'José & Ana?page=9#x');
      expect(url.queryParameters['page'], '1');
      expect(url.queryParameters['status'], 'active');
      expect(url.fragment, isEmpty);
    });

    test('parses items and paging', () async {
      answer(
        (_) =>
            jsonResponse(directoryPageJson(staffList(2), page: 1, lastPage: 2)),
      );

      final page = await people.fetchDirectoryPage();

      expect(page.items, hasLength(2));
      expect(page.hasMore, isTrue);
    });

    test('an unexpected shape is an ApiRequestException', () {
      answer((_) => jsonResponse({'data': <dynamic>[]}));

      expect(people.fetchDirectoryPage(), throwsA(isA<ApiRequestException>()));
    });
  });

  group('fetchStaff', () {
    test('GETs /staff/{publicId}', () async {
      answer((_) => jsonResponse({'data': staffMemberJson()}));

      final member = await people.fetchStaff('01J0STAFF000000000000000AA');

      expect(requests.single.path, '/api/v1/staff/01J0STAFF000000000000000AA');
      expect(member.fullName, 'Ada Lovelace');
    });

    test('a public id can never change the path', () async {
      answer((_) => jsonResponse({'data': staffMemberJson()}));

      await people.fetchStaff('../me/profile?x=1');

      expect(requests.single.pathSegments.last, '../me/profile?x=1');
      expect(requests.single.path, startsWith('/api/v1/staff/'));
      expect(requests.single.query, isEmpty);
    });
  });

  group('session rule (unchanged, Phase 27 §7.1)', () {
    test('401 ends the session', () async {
      answer((_) => jsonError(401));

      await expectLater(
        people.fetchDirectoryPage(),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      expect(auth.status, AuthStatus.unauthenticated);
    });

    test(
      '403 with a still-valid session is forbidden, not a sign-out',
      () async {
        answer((_) => jsonError(403, 'This action is unauthorized.'));

        await expectLater(
          people.fetchDirectoryPage(),
          throwsA(isA<ApiForbiddenException>()),
        );
        expect(auth.status, AuthStatus.authenticated);
        expect(backend.meCalls, greaterThanOrEqualTo(1));
      },
    );
  });
}

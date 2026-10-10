import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/work_logs/data/work_logs_api_client.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';

import '../../support/fake_backend.dart';
import '../../support/people_fixtures.dart';
import '../../support/work_log_fixtures.dart';

/// Phase 29B Gate 2 — [WorkLogsApiClient] over the real `ApiClient`:
/// exact paths and parameters, request bodies (only the editable fields on
/// update), the picker's sources, and failure mapping.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late WorkLogsApiClient client;
  late List<http.Request> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
    client = workLogsClientFor(backend, auth);
  });

  void answer(http.Response Function(http.Request) respond) {
    backend.onApi = (request) async {
      requests.add(request);

      return respond(request);
    };
  }

  group('fetchMyWorkLogs', () {
    test('GETs /me/work-logs with per_page 25 and the page', () async {
      answer((_) => jsonResponse(workLogsBody([workLogJson('L1')])));

      final page = await client.fetchMyWorkLogs(page: 3);

      final url = requests.single.url;
      expect(url.path, '/api/v1/me/work-logs');
      expect(url.queryParameters, {'per_page': '25', 'page': '3'});
      expect(requests.single.headers['Authorization'], 'Bearer token-a');
      expect(page.items.single.publicId, 'L1');
    });

    test('fetchCompanyDay asks for the smallest page', () async {
      answer((_) => jsonResponse(workLogsBody([], date: '2026-10-11')));

      final day = await client.fetchCompanyDay();

      expect(requests.single.url.queryParameters['per_page'], '1');
      expect(day.date, '2026-10-11');
    });

    test(
      'a 403 (no profile) keeps the session and is ApiForbiddenException',
      () async {
        answer(
          (_) => jsonError(403, 'No staff record is linked to this account.'),
        );

        await expectLater(
          client.fetchMyWorkLogs(),
          throwsA(isA<ApiForbiddenException>()),
        );
        expect(auth.status, AuthStatus.authenticated);
        expect(backend.meCalls, 2, reason: 'bootstrap plus the one re-check');
      },
    );

    test('an unexpected shape is an ApiRequestException', () {
      answer((_) => jsonResponse({'data': 'nope'}));

      expect(client.fetchMyWorkLogs(), throwsA(isA<ApiRequestException>()));
    });
  });

  group('create', () {
    test('a task log sends task_id only, with the three fields', () async {
      answer((_) => jsonResponse({'data': workLogJson('L9', taskTitle: 'T')}));

      final log = await client.create(
        target: const TaskTarget(publicId: 'T1', label: 'T'),
        workDate: '2026-10-10',
        durationMinutes: 90,
        description: 'Done.',
      );

      final request = requests.single;
      expect(request.method, 'POST');
      expect(request.url.path, '/api/v1/me/work-logs');
      expect(jsonDecode(request.body), {
        'task_id': 'T1',
        'work_date': '2026-10-10',
        'duration_minutes': 90,
        'description': 'Done.',
      });
      expect(log.publicId, 'L9');
    });

    test('a project log sends project_id only', () async {
      answer((_) => jsonResponse({'data': workLogJson('L9')}));

      await client.create(
        target: const ProjectTarget(publicId: 'P1', label: 'P'),
        workDate: '2026-10-09',
        durationMinutes: 30,
        description: 'Meeting.',
      );

      final body = jsonDecode(requests.single.body) as Map;
      expect(body['project_id'], 'P1');
      expect(body.containsKey('task_id'), isFalse);
    });

    test('a 422 carries the field errors', () async {
      answer(
        (_) => http.Response(
          jsonEncode({
            'message': 'The work date cannot be later than today.',
            'errors': {
              'work_date': ['The work date cannot be later than today.'],
            },
          }),
          422,
        ),
      );

      await expectLater(
        client.create(
          target: const ProjectTarget(publicId: 'P1', label: 'P'),
          workDate: '2026-10-11',
          durationMinutes: 30,
          description: 'x',
        ),
        throwsA(
          isA<ApiValidationException>().having(
            (e) => e.firstErrorFor('work_date'),
            'work_date',
            'The work date cannot be later than today.',
          ),
        ),
      );
    });
  });

  group('update and delete', () {
    test('update PATCHes only date, duration and description', () async {
      answer((_) => jsonResponse({'data': workLogJson('L1', minutes: 45)}));

      final log = await client.update(
        'a/b',
        workDate: '2026-10-08',
        durationMinutes: 45,
        description: 'Fixed.',
      );

      final request = requests.single;
      expect(request.method, 'PATCH');
      expect(request.url.path, '/api/v1/me/work-logs/a%2Fb');
      expect(jsonDecode(request.body), {
        'work_date': '2026-10-08',
        'duration_minutes': 45,
        'description': 'Fixed.',
      });
      expect(log.durationMinutes, 45);
    });

    test('delete accepts 204', () async {
      answer((_) => http.Response('', 204));

      await client.delete('L1');

      expect(requests.single.method, 'DELETE');
      expect(requests.single.url.path, '/api/v1/me/work-logs/L1');
    });

    test("someone else's log is a 404 request failure", () {
      answer((_) => jsonError(404, 'Not Found'));

      expect(
        client.delete('L1'),
        throwsA(
          isA<ApiRequestException>().having(
            (e) => e.statusCode,
            'statusCode',
            404,
          ),
        ),
      );
    });
  });

  group('picker sources', () {
    test(
      'fetchMyStaffPublicId reads /me/profile, null without a profile',
      () async {
        answer((_) => jsonResponse({'data': profileDataJson()}));
        expect(await client.fetchMyStaffPublicId(), isNotNull);
        expect(requests.single.url.path, '/api/v1/me/profile');

        answer((_) => jsonResponse({'data': profileDataJson(staff: null)}));
        expect(await client.fetchMyStaffPublicId(), isNull);
      },
    );

    test(
      'fetchMemberProjects filters by member and reads every page',
      () async {
        answer((request) {
          final page = int.parse(request.url.queryParameters['page']!);

          return jsonResponse(
            projectsBody(
              [projectJson('P$page-a'), projectJson('P$page-b')],
              page: page,
              lastPage: 3,
            ),
          );
        });

        final projects = await client.fetchMemberProjects('S1');

        expect(projects.map((p) => p.publicId), [
          'P1-a',
          'P1-b',
          'P2-a',
          'P2-b',
          'P3-a',
          'P3-b',
        ]);
        expect(requests, hasLength(3));
        for (final r in requests) {
          expect(r.url.path, '/api/v1/projects');
          expect(r.url.queryParameters['member'], 'S1');
          expect(r.url.queryParameters['per_page'], '50');
        }
      },
    );

    test('fetchMemberProjects stops at the page cap', () async {
      answer((request) {
        final page = int.parse(request.url.queryParameters['page']!);

        return jsonResponse(
          projectsBody([projectJson('P$page')], page: page, lastPage: 99),
        );
      });

      final projects = await client.fetchMemberProjects('S1');

      expect(projects, hasLength(WorkLogsApiClient.maxPickerPages));
    });
  });
}

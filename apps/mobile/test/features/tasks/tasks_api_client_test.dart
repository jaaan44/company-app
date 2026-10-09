import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/domain/task_item.dart';

import '../../support/fake_backend.dart';
import '../../support/task_fixtures.dart';

/// Phase 29A Gate 2 — [TasksApiClient] over the real `ApiClient` (spec
/// §5.1/§5.3): exact paths and parameters, the `PATCH` body, contract
/// failures, and the unchanged session rule.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late TasksApiClient tasks;
  late List<http.Request> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
    tasks = tasksClientFor(backend, auth);
  });

  void answer(http.Response Function(http.Request) respond) {
    backend.onApi = (request) async {
      requests.add(request);

      return respond(request);
    };
  }

  group('fetchMyTasks', () {
    test('GETs /me/tasks with the state, page size and page', () async {
      answer((_) => jsonResponse(myTasksBody([myTaskJson('T1')])));

      final page = await tasks.fetchMyTasks(
        state: TaskListState.closed,
        page: 2,
      );

      final url = requests.single.url;
      expect(requests.single.method, 'GET');
      expect(url.path, '/api/v1/me/tasks');
      expect(url.queryParameters, {
        'state': 'closed',
        'per_page': '25',
        'page': '2',
      });
      expect(requests.single.headers['Authorization'], 'Bearer token-a');
      expect(page.tasks!.single.publicId, 'T1');
    });

    test('defaults to page 1', () async {
      answer((_) => jsonResponse(myTasksBody([])));

      await tasks.fetchMyTasks(state: TaskListState.open);

      expect(requests.single.url.queryParameters['state'], 'open');
      expect(requests.single.url.queryParameters['page'], '1');
    });

    test('the no-profile response parses to tasks == null', () async {
      answer((_) => jsonResponse(myTasksBody(null)));

      expect(
        (await tasks.fetchMyTasks(state: TaskListState.open)).hasProfile,
        isFalse,
      );
    });

    test('an unexpected shape is an ApiRequestException, not a crash', () {
      answer(
        (_) => jsonResponse({
          'data': {'tasks': 'nope'},
        }),
      );

      expect(
        tasks.fetchMyTasks(state: TaskListState.open),
        throwsA(isA<ApiRequestException>()),
      );
    });
  });

  group('fetchTask', () {
    test('GETs /tasks/{publicId}, encoding the id', () async {
      answer((_) => jsonResponse({'data': taskJson(publicId: 'a/b')}));

      final task = await tasks.fetchTask('a/b');

      expect(requests.single.url.path, '/api/v1/tasks/a%2Fb');
      expect(task.publicId, 'a/b');
      expect(task.isOverdue, isNull);
    });

    test('a 404 is an ApiRequestException with its status', () {
      answer((_) => jsonError(404, 'Not found.'));

      expect(
        tasks.fetchTask('T1'),
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

  group('updateStatus', () {
    test(
      'PATCHes only the status, once, and returns the server task',
      () async {
        answer(
          (_) => jsonResponse({
            'data': taskJson(
              publicId: 'T1',
              status: 'completed',
              completedAt: '2026-09-24T05:00:00+00:00',
            ),
          }),
        );

        final task = await tasks.updateStatus('T1', TaskStatus.completed);

        final request = requests.single;
        expect(request.method, 'PATCH');
        expect(request.url.path, '/api/v1/tasks/T1');
        expect(jsonDecode(request.body), {'status': 'completed'});
        expect(task.status, TaskStatus.completed);
        expect(task.completedAt, DateTime.utc(2026, 9, 24, 5));
      },
    );

    test('sends the API wire value for in progress', () async {
      answer((_) => jsonResponse({'data': taskJson(status: 'in_progress')}));

      await tasks.updateStatus('T1', TaskStatus.inProgress);

      expect(jsonDecode(requests.single.body), {'status': 'in_progress'});
    });

    test('a 204 or malformed body is an ApiRequestException', () async {
      answer((_) => http.Response('', 204));
      await expectLater(
        tasks.updateStatus('T1', TaskStatus.todo),
        throwsA(isA<ApiRequestException>()),
      );

      answer(
        (_) => jsonResponse({
          'data': {'public_id': 'T1'},
        }),
      );
      await expectLater(
        tasks.updateStatus('T1', TaskStatus.todo),
        throwsA(isA<ApiRequestException>()),
      );
    });

    test(
      'a 403 keeps the session (after one re-check) and carries the message',
      () async {
        answer(
          (_) => jsonError(
            403,
            'You may only update the status of a task assigned to you.',
          ),
        );

        await expectLater(
          tasks.updateStatus('T1', TaskStatus.blocked),
          throwsA(
            isA<ApiForbiddenException>().having(
              (e) => e.message,
              'message',
              'You may only update the status of a task assigned to you.',
            ),
          ),
        );
        expect(auth.status, AuthStatus.authenticated);
        expect(requests, hasLength(1));
      },
    );

    test('a 401 ends the session', () async {
      answer((_) => jsonError(401));

      await expectLater(
        tasks.updateStatus('T1', TaskStatus.blocked),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      expect(auth.status, AuthStatus.unauthenticated);
    });
  });
}

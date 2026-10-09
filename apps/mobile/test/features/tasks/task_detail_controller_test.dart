import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/domain/task_item.dart';
import 'package:mobile/features/tasks/state/task_changes.dart';
import 'package:mobile/features/tasks/state/task_detail_controller.dart';

import '../../support/fake_backend.dart';
import '../../support/task_fixtures.dart';

/// Phase 29A Gate 2 — [TaskDetailController] over the real `ApiClient`
/// (spec §5.3, R-4/R-7): loading, the due state from the company date,
/// confirmed (non-optimistic) status saves, and each failure path.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late TasksApiClient client;
  late TaskChanges changes;
  late List<http.Request> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
    client = tasksClientFor(backend, auth);
    changes = TaskChanges();
  });

  void answer(Future<http.Response> Function(http.Request request) respond) {
    backend.onApi = (request) {
      requests.add(request);

      return respond(request);
    };
  }

  TaskDetailController detail({TaskItem? initial, String? companyDate}) {
    final c = TaskDetailController(
      client,
      'T1',
      initial: initial,
      companyDate: companyDate,
      changes: changes,
    );
    addTearDown(c.dispose);

    return c;
  }

  Future<http.Response> serve(
    http.Request request, {
    String status = 'todo',
  }) async {
    if (request.method == 'PATCH') {
      final next = (jsonDecode(request.body) as Map)['status'] as String;

      return jsonResponse({
        'data': taskJson(
          publicId: 'T1',
          status: next,
          dueDate: '2026-09-24',
          completedAt: next == 'completed' ? '2026-09-24T05:00:00+00:00' : null,
        ),
      });
    }

    return jsonResponse({
      'data': taskJson(publicId: 'T1', status: status, dueDate: '2026-09-24'),
    });
  }

  group('loading', () {
    test('without an initial item it shows loading, then the task', () async {
      answer(serve);
      final c = detail();
      expect(c.status, TaskDetailStatus.loading);

      await c.load();

      expect(c.status, TaskDetailStatus.loaded);
      expect(c.data!.title, 'Replace the lobby lights');
      expect(requests.single.url.path, '/api/v1/tasks/T1');
    });

    test(
      'with an initial item it opens loaded and refreshes quietly',
      () async {
        answer(serve);
        final initial = TaskItem.fromJson(
          myTaskJson(
            'T1',
            title: 'Old title',
            dueDate: '2026-09-24',
            isDueToday: true,
          ),
        );
        final c = detail(initial: initial);
        final states = <TaskDetailStatus>[];
        c.addListener(() => states.add(c.status));

        expect(c.status, TaskDetailStatus.loaded);
        await c.load();

        expect(states, everyElement(TaskDetailStatus.loaded));
        expect(c.data!.title, 'Replace the lobby lights');
        // The list's server flags survive a GET that carries none.
        expect(c.dueState, TaskDueState.dueToday);
      },
    );

    for (final (label, response, message)
        in <(String, Future<http.Response> Function(), String)>[
          (
            'network',
            () async => throw const NetworkFailure(),
            "Couldn't load this task. Check your connection.",
          ),
          (
            '403',
            () async =>
                jsonError(403, 'You do not have access to view this task.'),
            "You don't have access to this task.",
          ),
          (
            '404',
            () async => jsonError(404),
            'This task is no longer available.',
          ),
          (
            '500',
            () async => jsonError(500),
            'Something went wrong loading this task.',
          ),
        ]) {
      test('a $label failure with nothing loaded is the error state', () async {
        answer((_) => response());
        final c = detail();

        await c.load();

        expect(c.status, TaskDetailStatus.error);
        expect(c.errorMessage, message);
      });
    }

    test('a failed refresh keeps the task and reports false', () async {
      answer(serve);
      final c = detail();
      await c.load();
      answer((_) async => throw const NetworkFailure());

      expect(await c.refresh(), isFalse);
      expect(c.status, TaskDetailStatus.loaded);
      expect(c.data, isNotNull);
    });

    test(
      'the due state comes from the company date, never the device clock',
      () async {
        answer(serve);
        final c = detail();
        await c.load();

        expect(c.dueState, isNull);
        c.companyDate = '2026-09-24';
        expect(c.dueState, TaskDueState.dueToday);
        c.companyDate = '2026-09-25';
        expect(c.dueState, TaskDueState.overdue);
      },
    );
  });

  group('saving a status (confirmed, R-7)', () {
    test('the status changes only when the server confirms it', () async {
      final gate = Completer<http.Response>();
      answer((r) => r.method == 'PATCH' ? gate.future : serve(r));
      final c = detail();
      await c.load();

      final saving = c.saveStatus(TaskStatus.inProgress);
      await pumpEventQueue();

      expect(c.isSaving, isTrue);
      expect(c.canChangeStatus, isFalse);
      expect(c.data!.status, TaskStatus.todo, reason: 'not optimistic');
      expect(changes.revision, 0);

      gate.complete(
        await serve(
          http.Request('PATCH', Uri.parse('x'))
            ..body = jsonEncode({'status': 'in_progress'}),
        ),
      );
      expect(await saving, isTrue);

      expect(c.isSaving, isFalse);
      expect(c.data!.status, TaskStatus.inProgress);
      expect(c.saveMessage, isNull);
      expect(changes.revision, 1);
      expect(changes.last!.task!.status, TaskStatus.inProgress);
    });

    test(
      'completing then reopening records both and updates completed_at',
      () async {
        answer(serve);
        final c = detail();
        await c.load();

        expect(await c.saveStatus(TaskStatus.completed), isTrue);
        expect(c.data!.completedAt, DateTime.utc(2026, 9, 24, 5));
        expect(await c.saveStatus(TaskStatus.todo), isTrue);
        expect(c.data!.completedAt, isNull);
        expect(changes.revision, 2);
        expect(
          requests
              .where((r) => r.method == 'PATCH')
              .map((r) => jsonDecode(r.body)),
          [
            {'status': 'completed'},
            {'status': 'todo'},
          ],
        );
      },
    );

    test(
      'Cancelled, the current status, or a second save are not sent',
      () async {
        final gate = Completer<http.Response>();
        answer((r) => r.method == 'PATCH' ? gate.future : serve(r));
        final c = detail();
        await c.load();

        expect(await c.saveStatus(TaskStatus.cancelled), isFalse);
        expect(await c.saveStatus(TaskStatus.todo), isFalse);
        final first = c.saveStatus(TaskStatus.blocked);
        expect(await c.saveStatus(TaskStatus.completed), isFalse);
        gate.complete(
          await serve(
            http.Request('PATCH', Uri.parse('x'))
              ..body = jsonEncode({'status': 'blocked'}),
          ),
        );
        await first;

        expect(requests.where((r) => r.method == 'PATCH'), hasLength(1));
      },
    );

    test('a cancelled task is read-only (R-4)', () async {
      answer((r) => serve(r, status: 'cancelled'));
      final c = detail();
      await c.load();

      expect(c.showsStatusControl, isFalse);
      expect(c.canChangeStatus, isFalse);
      expect(await c.saveStatus(TaskStatus.todo), isFalse);
      expect(requests.where((r) => r.method == 'PATCH'), isEmpty);
    });

    test('nothing can be saved before the task has loaded', () async {
      answer(serve);
      final c = detail();

      expect(c.canChangeStatus, isFalse);
      expect(await c.saveStatus(TaskStatus.blocked), isFalse);
      expect(requests, isEmpty);
    });

    test(
      'a 403 shows the server message, reloads, locks, and records removal',
      () async {
        answer((r) async {
          if (r.method == 'PATCH') {
            return jsonError(
              403,
              'You may only update the status of a task assigned to you.',
            );
          }

          return serve(r);
        });
        final c = detail();
        await c.load();
        requests.clear();

        expect(await c.saveStatus(TaskStatus.completed), isFalse);

        expect(
          c.saveMessage,
          'You may only update the status of a task assigned to you.',
        );
        expect(c.data!.status, TaskStatus.todo);
        expect(c.canChangeStatus, isFalse);
        expect(requests.map((r) => r.method), ['PATCH', 'GET']);
        expect(changes.last!.task, isNull);
        expect(changes.last!.id, 'T1');
        expect(auth.status, AuthStatus.authenticated);
      },
    );

    test('a 403 whose reload also fails keeps the earlier task', () async {
      answer(serve);
      final c = detail();
      await c.load();
      answer((_) async => jsonError(403, 'Gone.'));

      await c.saveStatus(TaskStatus.blocked);

      expect(c.saveMessage, 'Gone.');
      expect(c.data!.status, TaskStatus.todo);
    });

    test('a 422 shows the status error, else the summary', () async {
      answer(serve);
      final c = detail();
      await c.load();
      answer(
        (_) async => http.Response(
          jsonEncode({
            'message': 'The given data was invalid.',
            'errors': {
              'status': ['The selected status is invalid.'],
            },
          }),
          422,
        ),
      );

      expect(await c.saveStatus(TaskStatus.blocked), isFalse);
      expect(c.saveMessage, 'The selected status is invalid.');
      expect(c.data!.status, TaskStatus.todo);
      expect(c.canChangeStatus, isTrue);

      answer((_) async => jsonError(422, 'Nope.'));
      await c.saveStatus(TaskStatus.blocked);
      expect(c.saveMessage, 'Nope.');
      expect(changes.revision, 0);
    });

    for (final (label, response, message)
        in <(String, Future<http.Response> Function(), String)>[
          (
            'network',
            () async => throw const NetworkFailure(),
            "Couldn't save the change. Check your connection and try again.",
          ),
          (
            '500',
            () async => jsonError(500),
            "Couldn't save the change. Please try again.",
          ),
          (
            '404',
            () async => jsonError(404),
            'This task is no longer available.',
          ),
        ]) {
      test(
        'a $label failure keeps the old status and allows a retry',
        () async {
          answer(serve);
          final c = detail();
          await c.load();
          answer((_) => response());

          expect(await c.saveStatus(TaskStatus.inProgress), isFalse);

          expect(c.saveMessage, message);
          expect(c.data!.status, TaskStatus.todo);
          expect(c.canChangeStatus, isTrue);
          expect(changes.revision, 0);

          answer(serve);
          expect(await c.saveStatus(TaskStatus.inProgress), isTrue);
          expect(c.saveMessage, isNull);
        },
      );
    }

    test('a 401 during a save ends the session and changes nothing', () async {
      answer(serve);
      final c = detail();
      await c.load();
      answer((_) async => jsonError(401));

      expect(await c.saveStatus(TaskStatus.completed), isFalse);

      expect(auth.status, AuthStatus.unauthenticated);
      expect(c.data!.status, TaskStatus.todo);
      expect(c.saveMessage, isNull);
    });

    test('a refresh that started before a save cannot undo it', () async {
      answer(serve);
      final c = detail();
      await c.load();
      final slowGet = Completer<http.Response>();
      answer((r) => r.method == 'GET' ? slowGet.future : serve(r));

      final refreshing = c.refresh();
      await pumpEventQueue();
      expect(await c.saveStatus(TaskStatus.completed), isTrue);
      slowGet.complete(
        jsonResponse({'data': taskJson(publicId: 'T1', status: 'todo')}),
      );
      await refreshing;

      expect(c.data!.status, TaskStatus.completed);
    });

    test('clearSaveMessage clears it', () async {
      answer(serve);
      final c = detail();
      await c.load();
      answer((_) async => jsonError(500));
      await c.saveStatus(TaskStatus.blocked);

      c.clearSaveMessage();

      expect(c.saveMessage, isNull);
    });
  });
}

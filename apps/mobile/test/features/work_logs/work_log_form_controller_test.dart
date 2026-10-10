import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';
import 'package:mobile/features/work_logs/state/work_log_changes.dart';
import 'package:mobile/features/work_logs/state/work_log_form_controller.dart';

import '../../support/fake_backend.dart';
import '../../support/people_fixtures.dart';
import '../../support/task_fixtures.dart'
    show myTaskJson, myTasksBody, tasksClientFor;
import '../../support/work_log_fixtures.dart';

/// Phase 29B Gate 2 — [WorkLogFormController] over the real `ApiClient`
/// (spec §6.4–§6.5, R-14…R-18): loading the company day and the picker,
/// client-side checks, confirmed saves and deletes, server errors on the
/// right fields, and the dirty state.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late WorkLogChanges changes;
  late List<http.Request> requests;

  /// The routes' answers; tests replace what they need.
  late Future<http.Response> Function(http.Request) onWrite;
  late Map<String, dynamic>? profileStaff;
  late List<Map<String, dynamic>>? openTasks;
  late List<Map<String, dynamic>> projects;
  late Object? workLogsAnswer;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    changes = WorkLogChanges();
    requests = [];
    profileStaff = {};
    openTasks = [
      myTaskJson('T1', title: 'Replace pump seal'),
      myTaskJson('T2', title: 'Call the supplier')..['project'] = null,
    ];
    projects = [
      projectJson('P1', name: 'Plant Room', status: 'active', code: 'PR-1'),
      projectJson('P2', name: 'Old Fit-out', status: 'completed'),
      projectJson('P3', name: 'Lobby', status: 'on_hold'),
      projectJson('P4', name: 'Dropped', status: 'cancelled'),
    ];
    workLogsAnswer = null;
    onWrite = (request) async => jsonResponse({'data': workLogJson('NEW')});

    backend.onApi = (request) async {
      requests.add(request);
      final path = request.url.path;
      if (request.method != 'GET') {
        return onWrite(request);
      }
      if (path.endsWith('/me/work-logs')) {
        final a = workLogsAnswer;
        if (a is http.Response) return a;
        if (a is Exception) throw a;

        return jsonResponse(workLogsBody([], date: '2026-10-10'));
      }
      if (path.endsWith('/me/tasks')) {
        return jsonResponse(myTasksBody(openTasks, date: '2026-10-10'));
      }
      if (path.endsWith('/me/profile')) {
        return jsonResponse({
          'data': profileStaff == null
              ? profileDataJson(staff: null)
              : profileDataJson(),
        });
      }
      if (path.endsWith('/projects')) {
        return jsonResponse(projectsBody(projects));
      }

      return jsonError(404);
    };
  });

  WorkLogFormController form({
    WorkLog? existing,
    WorkTarget? fixedTarget,
    String? companyDate,
  }) {
    final c = WorkLogFormController(
      workLogsClientFor(backend, auth),
      tasksClientFor(backend, auth),
      existing: existing,
      fixedTarget: fixedTarget,
      companyDate: companyDate,
      changes: changes,
    );
    addTearDown(c.dispose);

    return c;
  }

  WorkLog existingLog({String date = '2026-10-08', String? taskTitle}) =>
      WorkLog.fromJson(
        workLogJson(
          'L1',
          workDate: date,
          minutes: 95,
          description: 'Old text.',
          taskTitle: taskTitle,
        ),
      );

  Future<WorkLogFormController> readyCreateForm() async {
    final c = form();
    await c.load();
    expect(c.status, WorkLogFormStatus.ready);

    return c;
  }

  void fillValid(WorkLogFormController c) {
    c.setTarget(c.projectOptions.first);
    c.setDuration(hours: 1, minutes: 30);
    c.setDescription('  Checked the pump.  ');
  }

  List<String> paths() =>
      requests.map((r) => '${r.method} ${r.url.path}').toList();

  group('loading', () {
    test(
      'create: company day from /me/work-logs, then my tasks and projects',
      () async {
        final c = await readyCreateForm();

        expect(c.companyDate, '2026-10-10');
        expect(
          c.date,
          '2026-10-10',
          reason: 'the default date is the company today',
        );
        expect(c.isDirty, isFalse, reason: 'the default date is not an edit');
        expect(c.canChooseTarget, isTrue);
        expect(c.taskOptions.map((t) => (t.label, t.projectName)), [
          ('Replace pump seal', 'Fit-out'),
          ('Call the supplier', null),
        ]);
        // Completed and cancelled projects are hidden (R-14).
        expect(c.projectOptions.map((p) => p.label), ['Plant Room', 'Lobby']);
        expect(c.projectOptions.first.projectCode, 'PR-1');
        expect(
          requests
              .firstWhere((r) => r.url.path.endsWith('/projects'))
              .url
              .queryParameters['member'],
          isNotEmpty,
        );
        expect(
          requests
              .firstWhere((r) => r.url.path.endsWith('/me/tasks'))
              .url
              .queryParameters['state'],
          'open',
        );
      },
    );

    test('a given company day is used without asking the server', () async {
      final c = form(companyDate: '2026-10-09');
      await c.load();

      expect(c.date, '2026-10-09');
      expect(paths().where((p) => p.endsWith('/me/work-logs')), isEmpty);
    });

    test('from a task (fixed target): no picker is loaded', () async {
      final c = form(
        fixedTarget: const TaskTarget(
          publicId: 'T1',
          label: 'Replace pump seal',
        ),
        companyDate: '2026-10-10',
      );
      await c.load();

      expect(c.status, WorkLogFormStatus.ready);
      expect(c.canChooseTarget, isFalse);
      expect(c.target!.publicId, 'T1');
      expect(requests, isEmpty);
    });

    test('edit: fields come from the log; the target is read-only', () async {
      final c = form(
        existing: existingLog(taskTitle: 'Inspect wiring'),
        companyDate: '2026-10-10',
      );
      await c.load();

      expect(c.isEditing, isTrue);
      expect(c.canChooseTarget, isFalse);
      expect(
        c.target,
        isA<TaskTarget>().having((t) => t.label, 'label', 'Inspect wiring'),
      );
      expect(c.date, '2026-10-08');
      expect((c.hours, c.minutes), (1, 35));
      expect(c.description, 'Old text.');
      expect(c.isDirty, isFalse);

      c.setTarget(const ProjectTarget(publicId: 'P9', label: 'x'));
      expect(c.target!.publicId, isNot('P9'));
    });

    test('no profile: a 403 from /me/work-logs (R-13)', () async {
      workLogsAnswer = jsonError(
        403,
        'No staff record is linked to this account.',
      );
      final c = form();

      await c.load();

      expect(c.status, WorkLogFormStatus.noProfile);
      expect(auth.status, AuthStatus.authenticated);
    });

    test(
      'no profile: /me/tasks or /me/profile without a staff record',
      () async {
        openTasks = null;
        final a = form(companyDate: '2026-10-10');
        await a.load();
        expect(a.status, WorkLogFormStatus.noProfile);

        openTasks = [];
        profileStaff = null;
        final b = form(companyDate: '2026-10-10');
        await b.load();
        expect(b.status, WorkLogFormStatus.noProfile);
      },
    );

    test(
      'a load failure is the error state, and loading again recovers',
      () async {
        workLogsAnswer = const NetworkFailure();
        final c = form();

        await c.load();
        expect(c.status, WorkLogFormStatus.error);
        expect(c.loadError, "Couldn't load the form. Check your connection.");

        workLogsAnswer = null;
        await c.load();
        expect(c.status, WorkLogFormStatus.ready);
      },
    );
  });

  group('date bounds (R-17)', () {
    test('create: 365 days back to the company today', () async {
      final c = form(companyDate: '2026-10-10');

      expect(c.lastDate, '2026-10-10');
      expect(c.firstDate, '2025-10-10');
    });

    test('edit: an older log keeps its own date as the lower bound', () {
      final c = form(
        existing: existingLog(date: '2025-01-15'),
        companyDate: '2026-10-10',
      );

      expect(c.firstDate, '2025-01-15');
    });

    test('the year-back date is calendar arithmetic across a leap day', () {
      expect(form(companyDate: '2028-03-01').firstDate, '2027-03-02');
    });
  });

  group('checks before sending', () {
    test('each field is checked, with a message per field', () async {
      final c = await readyCreateForm();
      c.setDate('2026-10-11');
      c.setDuration(hours: 0, minutes: 0);
      c.setDescription('   ');

      expect(c.validate(), isFalse);

      expect(c.fieldErrors, {
        WorkLogField.target: 'Choose what this work was for.',
        WorkLogField.date: 'The work date cannot be later than today.',
        WorkLogField.duration: 'Enter how long you worked.',
        WorkLogField.description: 'Describe the work.',
      });
    });

    test('too long, too old, out-of-range minutes', () async {
      final c = await readyCreateForm();
      c.setTarget(c.taskOptions.first);
      c.setDate('2025-10-09');
      c.setDuration(hours: 24, minutes: 1);
      c.setDescription('x' * 2001);

      expect(c.validate(), isFalse);
      expect(
        c.fieldErrors[WorkLogField.date],
        'Choose a date within the last year.',
      );
      expect(
        c.fieldErrors[WorkLogField.duration],
        'A work log can be at most 24 hours.',
      );
      expect(
        c.fieldErrors[WorkLogField.description],
        'Use at most 2000 characters.',
      );

      c.setDuration(hours: 1, minutes: 60);
      c.validate();
      expect(c.fieldErrors[WorkLogField.duration], 'Enter hours and minutes.');
    });

    test('the limits themselves are accepted', () async {
      final c = await readyCreateForm();
      c.setTarget(c.taskOptions.first);
      c.setDate('2025-10-10');
      c.setDuration(hours: 24, minutes: 0);
      c.setDescription('x' * 2000);
      expect(c.validate(), isTrue);

      c.setDate('2026-10-10');
      c.setDuration(hours: 0, minutes: 1);
      expect(c.validate(), isTrue);
    });

    test('changing a field clears its error', () async {
      final c = await readyCreateForm();
      c.validate();
      expect(c.fieldErrors.keys, contains(WorkLogField.description));

      c.setDescription('Now described.');

      expect(c.fieldErrors.keys, isNot(contains(WorkLogField.description)));
    });

    test('nothing invalid is sent', () async {
      final c = await readyCreateForm();
      requests.clear();

      expect(await c.save(), isFalse);
      expect(requests, isEmpty);
    });
  });

  group('saving (confirmed, R-7)', () {
    test('create sends the target, date, total minutes and trimmed text, and records it', () async {
      final c = await readyCreateForm();
      fillValid(c);
      requests.clear();

      expect(await c.save(), isTrue);

      final post = requests.single;
      expect(post.method, 'POST');
      expect(jsonDecode(post.body), {
        'project_id': 'P1',
        'work_date': '2026-10-10',
        'duration_minutes': 90,
        'description': 'Checked the pump.',
      });
      expect(changes.revision, 1);
      expect(changes.last!.log!.publicId, 'NEW');
      expect(c.isDirty, isFalse, reason: 'saved values are the new baseline');
    });

    test('edit sends only the three fields', () async {
      final c = form(existing: existingLog(), companyDate: '2026-10-10');
      await c.load();
      c.setDuration(hours: 0, minutes: 45);
      onWrite = (r) async =>
          jsonResponse({'data': workLogJson('L1', minutes: 45)});

      expect(await c.save(), isTrue);

      final patch = requests.single;
      expect(patch.method, 'PATCH');
      expect(patch.url.path, '/api/v1/me/work-logs/L1');
      expect(jsonDecode(patch.body), {
        'work_date': '2026-10-08',
        'duration_minutes': 45,
        'description': 'Old text.',
      });
    });

    test('single-flight: a second save while saving sends nothing', () async {
      final c = await readyCreateForm();
      fillValid(c);
      final gate = Completer<http.Response>();
      onWrite = (r) => gate.future;
      requests.clear();

      final first = c.save();
      await pumpEventQueue();
      expect(c.isSaving, isTrue);
      expect(await c.save(), isFalse);
      gate.complete(jsonResponse({'data': workLogJson('NEW')}));
      expect(await first, isTrue);

      expect(requests.where((r) => r.method == 'POST'), hasLength(1));
    });

    test(
      "a server 422 lands on the form's fields; eligibility errors at the top",
      () async {
        final c = await readyCreateForm();
        fillValid(c);
        onWrite = (r) async => http.Response(
          jsonEncode({
            'message': 'The given data was invalid.',
            'errors': {
              'work_date': ['The work date cannot be later than today.'],
              'duration_minutes': [
                'The duration minutes field must not be greater than 1440.',
              ],
              'project_id': [
                'You must be a member of this project to log work against it.',
              ],
            },
          }),
          422,
        );

        expect(await c.save(), isFalse);

        expect(c.fieldErrors, {
          WorkLogField.date: 'The work date cannot be later than today.',
          WorkLogField.duration:
              'The duration minutes field must not be greater than 1440.',
        });
        expect(
          c.generalError,
          'You must be a member of this project to log work against it.',
        );
        expect(changes.revision, 0);
        expect(
          c.description,
          '  Checked the pump.  ',
          reason: 'what was typed stays',
        );
      },
    );

    test(
      'a 422 with no recognised field shows its message at the top',
      () async {
        final c = await readyCreateForm();
        fillValid(c);
        onWrite = (r) async =>
            jsonError(422, 'Something about this is invalid.');

        await c.save();

        expect(c.fieldErrors, isEmpty);
        expect(c.generalError, 'Something about this is invalid.');
      },
    );

    for (final (label, response, message)
        in <(String, Future<http.Response> Function(), String)>[
          (
            'offline',
            () async => throw const NetworkFailure(),
            "Couldn't save. Check your connection and try again.",
          ),
          (
            '500',
            () async => jsonError(500),
            "Couldn't save. Please try again.",
          ),
          ('403', () async => jsonError(403, 'Not allowed.'), 'Not allowed.'),
        ]) {
      test(
        '$label: a clear message, values kept, nothing recorded, retry works',
        () async {
          final c = await readyCreateForm();
          fillValid(c);
          onWrite = (r) => response();

          expect(await c.save(), isFalse);
          expect(c.generalError, message);
          expect(c.isDirty, isTrue);
          expect(changes.revision, 0);

          onWrite = (r) async => jsonResponse({'data': workLogJson('NEW')});
          expect(await c.save(), isTrue);
          expect(c.generalError, isNull);
        },
      );
    }

    test(
      'editing a log that no longer exists says so and records its removal',
      () async {
        final c = form(existing: existingLog(), companyDate: '2026-10-10');
        await c.load();
        c.setDescription('New.');
        onWrite = (r) async => jsonError(404, 'Not Found');

        expect(await c.save(), isFalse);

        expect(c.generalError, 'This work log is no longer available.');
        expect(changes.last!.isDeleted, isTrue);
        expect(changes.last!.id, 'L1');
      },
    );

    test('a 401 ends the session and records nothing', () async {
      final c = await readyCreateForm();
      fillValid(c);
      onWrite = (r) async => jsonError(401);

      expect(await c.save(), isFalse);

      expect(auth.status, AuthStatus.unauthenticated);
      expect(changes.revision, 0);
    });
  });

  group('deleting', () {
    test('deletes once and records it', () async {
      final c = form(existing: existingLog(), companyDate: '2026-10-10');
      await c.load();
      onWrite = (r) async => http.Response('', 204);

      expect(await c.delete(), isTrue);

      expect(requests.single.method, 'DELETE');
      expect(changes.last!.isDeleted, isTrue);
    });

    test('an already-deleted log counts as deleted', () async {
      final c = form(existing: existingLog(), companyDate: '2026-10-10');
      await c.load();
      onWrite = (r) async => jsonError(404);

      expect(await c.delete(), isTrue);
      expect(changes.last!.isDeleted, isTrue);
    });

    test('a failure keeps the log and says why', () async {
      final c = form(existing: existingLog(), companyDate: '2026-10-10');
      await c.load();
      onWrite = (r) async => throw const NetworkFailure();

      expect(await c.delete(), isFalse);

      expect(
        c.generalError,
        "Couldn't delete. Check your connection and try again.",
      );
      expect(changes.revision, 0);
    });

    test('a new log cannot be deleted', () async {
      final c = await readyCreateForm();
      requests.clear();

      expect(await c.delete(), isFalse);
      expect(requests, isEmpty);
    });
  });

  test('isDirty follows every field (R-18)', () async {
    final c = await readyCreateForm();
    expect(c.isDirty, isFalse);

    c.setDescription('x');
    expect(c.isDirty, isTrue);
    c.setDescription('');
    expect(c.isDirty, isFalse);

    c.setDuration(hours: 0, minutes: 5);
    expect(c.isDirty, isTrue);
    c.setDuration(hours: 0, minutes: 0);

    c.setDate('2026-10-09');
    expect(c.isDirty, isTrue);
    c.setDate('2026-10-10');

    c.setTarget(c.taskOptions.first);
    expect(c.isDirty, isTrue);
  });
}

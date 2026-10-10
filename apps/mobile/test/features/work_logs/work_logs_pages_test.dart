import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/tasks/presentation/task_detail_page.dart';
import 'package:mobile/features/work_logs/presentation/my_work_logs_page.dart';
import 'package:mobile/features/work_logs/presentation/work_log_form_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart';
import '../../support/people_fixtures.dart' show profileDataJson;
import '../../support/task_fixtures.dart';
import '../../support/work_log_fixtures.dart';

/// Phase 29B Gate 3 — the work-log screens through the real app
/// (`CompanyApp`, router, shell, `ApiClient`) against an in-memory work-log
/// API (docs/phases/V1_PHASE_29_DEFINITION.md §6.5, R-14…R-18).
void main() {
  const today = '2026-10-10';

  late FakeBackend backend;
  late AuthController auth;
  late List<Map<String, dynamic>> logs;
  late List<http.Request> requests;
  late int nextId;

  /// Overrides, per test.
  Future<http.Response> Function(http.Request)? onWrite;
  Future<http.Response> Function()? onList;

  Map<String, dynamic> openTask(
    String id,
    String title, {
    String status = 'todo',
    bool project = true,
  }) => taskJson(
    publicId: id,
    title: title,
    status: status,
    dueDate: today,
    project: project ? const {'public_id': 'P1', 'name': 'Plant Room'} : null,
  );

  late Map<String, Map<String, dynamic>> tasks;

  Future<http.Response> route(http.Request request) async {
    requests.add(request);
    final path = request.url.path;

    if (path.endsWith('/me/home')) {
      return homeResponse();
    }
    if (path.endsWith('/me/profile')) {
      return jsonResponse({'data': profileDataJson()});
    }
    if (path.endsWith('/projects')) {
      return jsonResponse(
        projectsBody([
          projectJson('P1', name: 'Plant Room', code: 'PR-1'),
          projectJson('P2', name: 'Old Fit-out', status: 'completed'),
        ]),
      );
    }
    if (path.endsWith('/me/tasks')) {
      final closed = request.url.queryParameters['state'] == 'closed';
      final list = tasks.values
          .where(
            (t) =>
                (t['status'] == 'completed' || t['status'] == 'cancelled') ==
                closed,
          )
          .map(
            (t) => {
              ...t,
              'is_overdue': false,
              'is_due_today': t['due_date'] == today,
            },
          )
          .toList();

      return jsonResponse(myTasksBody(list, date: today));
    }
    if (path.contains('/tasks/') && request.method == 'GET') {
      return jsonResponse({'data': tasks[path.split('/').last]!});
    }

    // Work logs.
    if (request.method != 'GET') {
      if (onWrite case final custom?) {
        return custom(request);
      }
      if (request.method == 'DELETE') {
        logs.removeWhere((l) => l['public_id'] == path.split('/').last);

        return http.Response('', 204);
      }
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      if (request.method == 'POST') {
        final taskId = body['task_id'] as String?;
        final log = workLogJson(
          'NEW${nextId++}',
          workDate: body['work_date'] as String,
          minutes: body['duration_minutes'] as int,
          description: body['description'] as String,
          taskTitle: taskId == null ? null : tasks[taskId]!['title'] as String,
          projectName: taskId == null || tasks[taskId]!['project'] != null
              ? 'Plant Room'
              : null,
        );
        logs.insert(0, log);

        return http.Response(jsonEncode({'data': log}), 201);
      }
      final i = logs.indexWhere((l) => l['public_id'] == path.split('/').last);
      logs[i] = {
        ...logs[i],
        'work_date': body['work_date'],
        'duration_minutes': body['duration_minutes'],
        'description': body['description'],
      };

      return jsonResponse({'data': logs[i]});
    }

    if (onList case final custom?) {
      return custom();
    }
    final perPage = int.parse(request.url.queryParameters['per_page'] ?? '25');
    final sorted = [...logs]
      ..sort(
        (a, b) =>
            (b['work_date'] as String).compareTo(a['work_date'] as String),
      );

    return jsonResponse(
      workLogsBody(sorted.take(perPage).toList(), date: today),
    );
  }

  setUp(() {
    nextId = 1;
    requests = [];
    onWrite = null;
    onList = null;
    tasks = {
      'T1': openTask('T1', 'Replace pump seal'),
      'T2': openTask('T2', 'Call the supplier', project: false),
      'T9': openTask('T9', 'Dropped request', status: 'cancelled'),
    };
    logs = [
      workLogJson(
        'A',
        workDate: today,
        minutes: 90,
        description: 'Swapped the seal.',
        taskTitle: 'Replace pump seal',
        projectName: 'Plant Room',
      ),
      workLogJson(
        'B',
        workDate: today,
        minutes: 30,
        description: 'Site meeting.',
        projectName: 'Plant Room',
      ),
      workLogJson(
        'C',
        workDate: '2026-10-09',
        minutes: 45,
        description: 'Ordered parts.',
        projectName: 'Plant Room',
      ),
      workLogJson(
        'D',
        workDate: '2026-10-07',
        minutes: 120,
        description: 'Survey.',
        projectName: 'Plant Room',
      ),
    ];
  });

  setUpAll(() => WidgetController.hitTestWarningShouldBeFatal = true);
  tearDownAll(() => WidgetController.hitTestWarningShouldBeFatal = false);

  Future<void> pumpApp(WidgetTester tester) async {
    tester.view.physicalSize =
        const Size(800, 2400) * tester.view.devicePixelRatio;
    addTearDown(tester.view.resetPhysicalSize);
    backend = FakeBackend()..onApi = route;
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));

    await tester.pumpWidget(
      CompanyApp(
        authController: auth,
        homeApiClient: homeClientFor(backend, auth),
        tasksApiClient: tasksClientFor(backend, auth),
        workLogsApiClient: workLogsClientFor(backend, auth),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> openWorkLogs(WidgetTester tester) async {
    await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('more-work-logs')));
    await tester.pumpAndSettle();
  }

  Future<void> openAdd(WidgetTester tester) async {
    await openWorkLogs(tester);
    await tester.tap(find.byKey(const Key('work-logs-add')));
    await tester.pumpAndSettle();
  }

  Finder row(String id) => find.byKey(Key('work-log-row-$id'));

  List<http.Request> writes() =>
      requests.where((r) => r.method != 'GET').toList();

  Future<void> fill(
    WidgetTester tester, {
    String? hours,
    String? minutes,
    String? text,
  }) async {
    if (hours != null) {
      await tester.enterText(find.byKey(const Key('work-log-hours')), hours);
    }
    if (minutes != null) {
      await tester.enterText(
        find.byKey(const Key('work-log-minutes')),
        minutes,
      );
    }
    if (text != null) {
      await tester.enterText(
        find.byKey(const Key('work-log-description')),
        text,
      );
    }
    await tester.pump();
  }

  Future<void> chooseTarget(WidgetTester tester, String id) async {
    await tester.tap(find.byKey(const Key('work-log-target')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key('work-target-$id')));
    await tester.pumpAndSettle();
  }

  Future<void> save(WidgetTester tester) async {
    await tester.ensureVisible(find.byKey(const Key('work-log-save')));
    await tester.tap(find.byKey(const Key('work-log-save')));
    await tester.pumpAndSettle();
  }

  group('My work logs', () {
    testWidgets('More → My work logs: grouped by date with durations', (
      tester,
    ) async {
      await pumpApp(tester);
      await openWorkLogs(tester);

      expect(find.byType(MyWorkLogsPage), findsOneWidget);
      final headers = ['Today', 'Yesterday', 'Wed 7 Oct'];
      final ys = headers
          .map((h) => tester.getTopLeft(find.text(h)).dy)
          .toList();
      expect(ys, [...ys]..sort(), reason: 'newest date first');

      expect(
        find.descendant(of: row('A'), matching: find.text('Replace pump seal')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: row('A'), matching: find.text('Plant Room')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: row('A'), matching: find.text('1 h 30 min')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: row('B'), matching: find.text('Plant Room')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: row('B'), matching: find.text('30 min')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: row('D'), matching: find.text('2 h')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('work-logs-add')), findsOneWidget);
    });

    testWidgets('empty state', (tester) async {
      logs.clear();
      await pumpApp(tester);
      await openWorkLogs(tester);

      expect(find.text('No work logged yet.'), findsOneWidget);
    });

    testWidgets('no profile: the message and no Add button (R-13)', (
      tester,
    ) async {
      onList = () async =>
          jsonError(403, 'No staff record is linked to this account.');
      await pumpApp(tester);
      await openWorkLogs(tester);

      expect(
        find.text('No staff profile is linked to this account.'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('work-logs-add')), findsNothing);
      expect(auth.status, AuthStatus.authenticated);
    });

    testWidgets('a load failure offers Try again', (tester) async {
      onList = () async => throw const NetworkFailure();
      await pumpApp(tester);
      await openWorkLogs(tester);

      expect(
        find.text("Couldn't load your work logs. Check your connection."),
        findsOneWidget,
      );

      onList = null;
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();
      expect(row('A'), findsOneWidget);
    });

    testWidgets('more pages load at the end; a failed page can be retried', (
      tester,
    ) async {
      var fail = true;
      onList = () async {
        final page = int.parse(requests.last.url.queryParameters['page']!);
        if (page == 2 && fail) {
          throw const NetworkFailure();
        }

        return jsonResponse(
          workLogsBody(
            [
              for (var i = 1; i <= 3; i++)
                workLogJson(
                  'P$page-$i',
                  workDate: page == 1 ? today : '2026-10-01',
                ),
            ],
            page: page,
            lastPage: 2,
            date: today,
          ),
        );
      };
      await pumpApp(tester);
      await openWorkLogs(tester);

      expect(
        find.byKey(const Key('work-logs-load-more-retry')),
        findsOneWidget,
      );
      fail = false;
      await tester.tap(find.byKey(const Key('work-logs-load-more-retry')));
      await tester.pumpAndSettle();

      expect(row('P2-3'), findsOneWidget);
      expect(find.text('Thu 1 Oct'), findsOneWidget);
    });
  });

  group('adding', () {
    testWidgets(
      'the picker offers my open tasks and my open projects only (R-14)',
      (tester) async {
        await pumpApp(tester);
        await openAdd(tester);

        expect(find.byType(WorkLogFormPage), findsOneWidget);
        expect(find.text('Log work'), findsOneWidget);
        expect(
          find.text('Sat 10 Oct 2026'),
          findsOneWidget,
          reason: 'defaults to the company today',
        );

        await tester.tap(find.byKey(const Key('work-log-target')));
        await tester.pumpAndSettle();

        final picker = find.byKey(const Key('work-target-picker'));
        for (final shown in [
          'Replace pump seal',
          'Call the supplier',
          'Plant Room',
        ]) {
          expect(
            find.descendant(of: picker, matching: find.text(shown)),
            findsWidgets,
            reason: shown,
          );
        }
        expect(
          find.descendant(of: picker, matching: find.text('Dropped request')),
          findsNothing,
        );
        expect(
          find.descendant(of: picker, matching: find.text('Old Fit-out')),
          findsNothing,
        );
      },
    );

    testWidgets(
      'logging against a project: saved, announced, listed under Today',
      (tester) async {
        await pumpApp(tester);
        await openAdd(tester);
        await chooseTarget(tester, 'P1');
        await fill(
          tester,
          hours: '1',
          minutes: '30',
          text: 'Checked the pump.',
        );

        await save(tester);

        final post = writes().single;
        expect(jsonDecode(post.body), {
          'project_id': 'P1',
          'work_date': today,
          'duration_minutes': 90,
          'description': 'Checked the pump.',
        });
        expect(find.text('Work logged.'), findsOneWidget);
        expect(find.byType(WorkLogFormPage), findsNothing);
        expect(row('NEW1'), findsOneWidget, reason: 'the list refreshed');
      },
    );

    testWidgets('checks before sending: each field says what is wrong', (
      tester,
    ) async {
      await pumpApp(tester);
      await openAdd(tester);

      await save(tester);

      expect(find.text('Choose what this work was for.'), findsOneWidget);
      expect(find.text('Enter how long you worked.'), findsOneWidget);
      expect(find.text('Describe the work.'), findsOneWidget);
      expect(writes(), isEmpty);

      await fill(tester, hours: '25');
      await save(tester);
      expect(find.text('A work log can be at most 24 hours.'), findsOneWidget);
    });

    testWidgets('the date picker stops at the company today', (tester) async {
      await pumpApp(tester);
      await openAdd(tester);

      await tester.tap(find.byKey(const Key('work-log-date')));
      await tester.pumpAndSettle();
      // 11 Oct is after the company today: disabled, so choosing it and
      // pressing OK leaves the date unchanged.
      await tester.tap(find.text('11'), warnIfMissed: false);
      await tester.tap(find.text('OK'));
      await tester.pumpAndSettle();
      expect(find.text('Sat 10 Oct 2026'), findsOneWidget);

      await tester.tap(find.byKey(const Key('work-log-date')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('9'));
      await tester.tap(find.text('OK'));
      await tester.pumpAndSettle();

      expect(find.text('Fri 9 Oct 2026'), findsOneWidget);
    });

    testWidgets("the server's refusal is shown on the form", (tester) async {
      onWrite = (r) async => http.Response(
        jsonEncode({
          'message': 'The given data was invalid.',
          'errors': {
            'project_id': [
              'You must be a member of this project to log work against it.',
            ],
          },
        }),
        422,
      );
      await pumpApp(tester);
      await openAdd(tester);
      await chooseTarget(tester, 'P1');
      await fill(tester, minutes: '20', text: 'x');

      await save(tester);

      expect(
        find.text(
          'You must be a member of this project to log work against it.',
        ),
        findsOneWidget,
      );
      expect(find.byType(WorkLogFormPage), findsOneWidget);
    });

    testWidgets(
      'offline: a clear message, what was typed stays, and it saves later',
      (tester) async {
        onWrite = (r) async => throw const NetworkFailure();
        await pumpApp(tester);
        await openAdd(tester);
        await chooseTarget(tester, 'T2');
        await fill(tester, minutes: '15', text: 'Called them.');

        await save(tester);

        expect(
          find.text("Couldn't save. Check your connection and try again."),
          findsOneWidget,
        );
        expect(find.text('Called them.'), findsOneWidget);

        onWrite = null;
        await save(tester);
        expect(find.text('Work logged.'), findsOneWidget);
      },
    );

    testWidgets('a revoked session during a save returns to Login', (
      tester,
    ) async {
      onWrite = (r) async => jsonError(401);
      await pumpApp(tester);
      await openAdd(tester);
      await chooseTarget(tester, 'P1');
      await fill(tester, minutes: '10', text: 'x');

      await save(tester);

      expect(auth.status, AuthStatus.unauthenticated);
      expect(
        find.text('Your session has ended. Please sign in again.'),
        findsOneWidget,
      );
    });
  });

  group('editing and deleting', () {
    Future<void> openEdit(WidgetTester tester, String id) async {
      await openWorkLogs(tester);
      await tester.tap(row(id));
      await tester.pumpAndSettle();
    }

    testWidgets(
      'edit: values shown, target read-only, only the three fields sent',
      (tester) async {
        await pumpApp(tester);
        await openEdit(tester, 'A');

        expect(find.text('Edit work log'), findsOneWidget);
        expect(find.text('Replace pump seal'), findsOneWidget);
        expect(find.text('Swapped the seal.'), findsOneWidget);
        await tester.tap(
          find.byKey(const Key('work-log-target')),
          warnIfMissed: false,
        );
        await tester.pumpAndSettle();
        expect(find.byKey(const Key('work-target-picker')), findsNothing);

        await fill(tester, hours: '2', minutes: '0');
        await save(tester);

        final patch = writes().single;
        expect(patch.method, 'PATCH');
        expect(jsonDecode(patch.body), {
          'work_date': today,
          'duration_minutes': 120,
          'description': 'Swapped the seal.',
        });
        expect(find.text('Changes saved.'), findsOneWidget);
        expect(
          find.descendant(of: row('A'), matching: find.text('2 h')),
          findsOneWidget,
        );
      },
    );

    testWidgets('leaving with unsaved changes asks first (R-18)', (
      tester,
    ) async {
      await pumpApp(tester);
      await openEdit(tester, 'B');
      await fill(tester, text: 'Changed my mind.');

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('Discard changes?'), findsOneWidget);

      await tester.tap(find.text('Keep editing'));
      await tester.pumpAndSettle();
      expect(find.byType(WorkLogFormPage), findsOneWidget);
      expect(find.text('Changed my mind.'), findsOneWidget);

      await tester.pageBack();
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('work-log-discard-confirm')));
      await tester.pumpAndSettle();
      expect(find.byType(WorkLogFormPage), findsNothing);
      expect(
        find.descendant(of: row('B'), matching: find.text('Site meeting.')),
        findsOneWidget,
      );
      expect(writes(), isEmpty);
    });

    testWidgets('leaving without changes does not ask', (tester) async {
      await pumpApp(tester);
      await openEdit(tester, 'B');

      await tester.pageBack();
      await tester.pumpAndSettle();

      expect(find.text('Discard changes?'), findsNothing);
      expect(find.byType(MyWorkLogsPage), findsOneWidget);
    });

    testWidgets('delete asks for confirmation, then removes it (R-18)', (
      tester,
    ) async {
      await pumpApp(tester);
      await openEdit(tester, 'C');

      await tester.ensureVisible(find.byKey(const Key('work-log-delete')));
      await tester.tap(find.byKey(const Key('work-log-delete')));
      await tester.pumpAndSettle();
      expect(find.text('Delete this work log?'), findsOneWidget);

      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      expect(writes(), isEmpty);

      await tester.tap(find.byKey(const Key('work-log-delete')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('work-log-delete-confirm')));
      await tester.pumpAndSettle();

      expect(writes().single.method, 'DELETE');
      expect(find.text('Work log deleted.'), findsOneWidget);
      expect(row('C'), findsNothing);
    });
  });

  group('from a task (R-15)', () {
    testWidgets(
      '"Log work" fixes the task, and My work logs shows it afterwards',
      (tester) async {
        await pumpApp(tester);
        await tester.tap(find.widgetWithText(NavigationDestination, 'Tasks'));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const Key('task-row-T1')));
        await tester.pumpAndSettle();

        await tester.tap(find.byKey(const Key('task-log-work')));
        await tester.pumpAndSettle();

        expect(find.byType(WorkLogFormPage), findsOneWidget);
        final router = GoRouter.of(
          tester.element(find.byType(WorkLogFormPage)),
        );
        expect(router.state.matchedLocation, '/tasks/T1/log-work');
        expect(
          find.descendant(
            of: find.byKey(const Key('work-log-target')),
            matching: find.text('Replace pump seal'),
          ),
          findsOneWidget,
        );
        expect(find.text('Sat 10 Oct 2026'), findsOneWidget);

        await fill(tester, minutes: '40', text: 'More seal work.');
        await save(tester);

        expect(jsonDecode(writes().single.body), {
          'task_id': 'T1',
          'work_date': today,
          'duration_minutes': 40,
          'description': 'More seal work.',
        });
        expect(
          find.byType(TaskDetailPage),
          findsOneWidget,
          reason: 'back on the task',
        );

        await openWorkLogs(tester);
        expect(row('NEW1'), findsOneWidget);
      },
    );

    testWidgets('a cancelled task offers no "Log work"', (tester) async {
      await pumpApp(tester);
      await tester.tap(find.widgetWithText(NavigationDestination, 'Tasks'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Done'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('task-row-T9')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('task-log-work')), findsNothing);
    });
  });

  group('appearance', () {
    for (final (label, brightness) in [
      ('light', Brightness.light),
      ('dark', Brightness.dark),
    ]) {
      testWidgets(
        'list, form and picker render without overflow in $label mode at 200% text',
        (tester) async {
          tester.platformDispatcher.platformBrightnessTestValue = brightness;
          tester.platformDispatcher.textScaleFactorTestValue = 2.0;
          addTearDown(tester.platformDispatcher.clearAllTestValues);
          logs.add(
            workLogJson(
              'LONG',
              workDate: '2026-10-08',
              taskTitle:
                  'A very long task title that keeps going well past one line',
              description: 'A long description ' * 10,
            ),
          );

          await pumpApp(tester);
          await openWorkLogs(tester);
          expect(tester.takeException(), isNull);

          await tester.tap(find.byKey(const Key('work-logs-add')));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          await tester.tap(find.byKey(const Key('work-log-target')));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          expect(
            Theme.of(tester.element(find.byType(WorkLogFormPage))).brightness,
            brightness,
          );
        },
      );
    }
  });
}

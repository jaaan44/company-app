import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/tasks/presentation/task_detail_page.dart';
import 'package:mobile/features/tasks/presentation/tasks_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart';
import '../../support/task_fixtures.dart';

/// Phase 29A Gate 3 — the Tasks list and detail screens through the real
/// app (`CompanyApp`, router, shell, `ApiClient`), against an in-memory
/// task API (docs/phases/V1_PHASE_29_DEFINITION.md §5.3, R-4…R-7).
void main() {
  const today = '2026-09-24';

  late FakeBackend backend;
  late AuthController auth;
  late Map<String, Map<String, dynamic>> store;
  late List<http.Request> requests;
  late int homeRequests;

  /// When set, answers `PATCH /tasks/{id}` instead of the store.
  Future<http.Response> Function(http.Request request)? onPatch;

  /// When set, answers `GET /me/tasks` instead of the store.
  Future<http.Response> Function(Uri url)? onList;

  /// When set, answers `GET /tasks/{id}` instead of the store.
  Future<http.Response> Function(String id)? onShow;

  Map<String, dynamic> task(
    String id,
    String title, {
    String status = 'todo',
    String? due,
    bool project = true,
    String? description = 'Both floors.',
  }) => taskJson(
    publicId: id,
    title: title,
    status: status,
    dueDate: due,
    description: description,
    completedAt: status == 'completed' ? '2026-09-20T03:00:00+00:00' : null,
    project: project
        ? const {'public_id': '01PROJ00000000000000000001', 'name': 'Fit-out'}
        : null,
  );

  bool isClosed(Map<String, dynamic> t) =>
      t['status'] == 'completed' || t['status'] == 'cancelled';

  /// `/me/tasks` as the server computes it: state filter and flags.
  Map<String, dynamic> listed(Map<String, dynamic> t) {
    final due = t['due_date'] as String?;
    final open = !isClosed(t);

    return {
      ...t,
      'is_overdue': open && due != null && due.compareTo(today) < 0,
      'is_due_today': open && due == today,
    };
  }

  Future<http.Response> route(http.Request request) async {
    requests.add(request);
    final path = request.url.path;

    if (path.endsWith('/me/home')) {
      homeRequests++;

      return homeResponse();
    }

    if (path.endsWith('/me/tasks')) {
      if (onList case final custom?) {
        return custom(request.url);
      }
      final closed = request.url.queryParameters['state'] == 'closed';

      return jsonResponse(
        myTasksBody(
          store.values.where((t) => isClosed(t) == closed).map(listed).toList(),
          date: today,
        ),
      );
    }

    final id = path.split('/').last;

    if (request.method == 'PATCH') {
      if (onPatch case final custom?) {
        return custom(request);
      }
      final status = (jsonDecode(request.body) as Map)['status'] as String;
      store[id] = {
        ...store[id]!,
        'status': status,
        'completed_at': status == 'completed'
            ? '2026-09-24T05:00:00+00:00'
            : null,
      };

      return jsonResponse({'data': store[id]!});
    }

    if (onShow case final custom?) {
      return custom(id);
    }

    final found = store[id];

    return found == null
        ? jsonError(404, 'Not found.')
        : jsonResponse({'data': found});
  }

  setUp(() {
    store = {
      for (final t in [
        task('T-over', 'Fix the pump', due: '2026-09-03'),
        task('T-today', 'Call the supplier', due: today, project: false),
        task('T-later', 'Order filters', due: '2026-10-12'),
        task('T-none', 'Tidy the store', description: null),
        task('T-done', 'Old job', status: 'completed', due: '2026-09-01'),
        task('T-cancel', 'Dropped job', status: 'cancelled'),
      ])
        t['public_id'] as String: t,
    };
    requests = [];
    homeRequests = 0;
    onPatch = null;
    onList = null;
    onShow = null;
  });

  // A gesture that misses its target must fail the test.
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
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> openTasks(WidgetTester tester) async {
    await tester.tap(find.widgetWithText(NavigationDestination, 'Tasks'));
    await tester.pumpAndSettle();
  }

  Future<void> openTask(WidgetTester tester, String id) async {
    await tester.tap(find.byKey(Key('task-row-$id')));
    await tester.pumpAndSettle();
  }

  Future<void> selectDone(WidgetTester tester) async {
    await tester.tap(find.text('Done'));
    await tester.pumpAndSettle();
  }

  Finder row(String id) => find.byKey(Key('task-row-$id'));

  Finder inRow(String id, Finder matching) =>
      find.descendant(of: row(id), matching: matching);

  List<http.Request> listRequests(String state) => requests
      .where(
        (r) =>
            r.url.path.endsWith('/me/tasks') &&
            r.url.queryParameters['state'] == state,
      )
      .toList();

  group('list', () {
    testWidgets(
      'Open lists my open tasks in server order with due labels, project and status',
      (tester) async {
        await pumpApp(tester);
        await openTasks(tester);

        expect(find.byType(TasksPage), findsOneWidget);
        expect(listRequests('open'), hasLength(1));
        expect(listRequests('closed'), isEmpty);

        for (final id in ['T-over', 'T-today', 'T-later', 'T-none']) {
          expect(row(id), findsOneWidget);
        }
        expect(row('T-done'), findsNothing);
        expect(row('T-cancel'), findsNothing);

        expect(inRow('T-over', find.text('Overdue · 3 Sep')), findsOneWidget);
        expect(inRow('T-today', find.text('Due today')), findsOneWidget);
        expect(inRow('T-later', find.text('Due 12 Oct')), findsOneWidget);
        expect(inRow('T-none', find.textContaining('Due')), findsNothing);
        expect(inRow('T-over', find.text('Fit-out')), findsOneWidget);
        expect(inRow('T-today', find.text('Fit-out')), findsNothing);
        // The status chip always carries text, not just colour.
        expect(inRow('T-over', find.text('To do')), findsOneWidget);
      },
    );

    testWidgets(
      'Done loads once on first selection; switching keeps both lists',
      (tester) async {
        await pumpApp(tester);
        await openTasks(tester);

        await selectDone(tester);

        expect(listRequests('closed'), hasLength(1));
        expect(row('T-done'), findsOneWidget);
        expect(row('T-cancel'), findsOneWidget);
        expect(inRow('T-done', find.text('Completed')), findsOneWidget);
        expect(inRow('T-cancel', find.text('Cancelled')), findsOneWidget);
        // A closed task is never shown as overdue.
        expect(inRow('T-done', find.text('Due 1 Sep')), findsOneWidget);

        await tester.tap(find.text('Open'));
        await tester.pumpAndSettle();
        await selectDone(tester);

        expect(listRequests('open'), hasLength(1));
        expect(listRequests('closed'), hasLength(1));
      },
    );

    testWidgets('empty states for each segment', (tester) async {
      store.clear();
      await pumpApp(tester);
      await openTasks(tester);

      expect(find.text('No open tasks assigned to you.'), findsOneWidget);

      await selectDone(tester);
      expect(find.text('No completed tasks yet.'), findsOneWidget);
    });

    testWidgets('no linked profile shows the no-profile state', (tester) async {
      onList = (_) async => jsonResponse(myTasksBody(null));
      await pumpApp(tester);
      await openTasks(tester);

      expect(
        find.text('No staff profile is linked to this account.'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('tasks-empty')), findsNothing);
    });

    testWidgets('a load failure shows the error and Try again recovers', (
      tester,
    ) async {
      onList = (_) async => throw const NetworkFailure();
      await pumpApp(tester);
      await openTasks(tester);

      expect(
        find.text("Couldn't load your tasks. Check your connection."),
        findsOneWidget,
      );

      onList = null;
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();

      expect(row('T-over'), findsOneWidget);
    });

    testWidgets(
      'more pages load at the end; a failed page becomes a retry row',
      (tester) async {
        var failPage2 = true;
        onList = (url) async {
          final page = int.parse(url.queryParameters['page']!);
          if (page == 2 && failPage2) {
            throw const NetworkFailure();
          }

          return jsonResponse(
            myTasksBody(
              [
                for (var i = 1; i <= 3; i++)
                  listed(task('P$page-$i', 'Page $page task $i')),
              ],
              page: page,
              lastPage: 2,
            ),
          );
        };
        await pumpApp(tester);
        await openTasks(tester);

        expect(find.byKey(const Key('tasks-load-more-retry')), findsOneWidget);
        expect(row('P2-1'), findsNothing);

        failPage2 = false;
        await tester.tap(find.byKey(const Key('tasks-load-more-retry')));
        await tester.pumpAndSettle();

        expect(row('P2-3'), findsOneWidget);
        expect(find.byKey(const Key('tasks-load-more-retry')), findsNothing);
        expect(find.byKey(const Key('tasks-loading-more')), findsNothing);
      },
    );

    testWidgets('a row opens its task inside the Tasks tab; back returns', (
      tester,
    ) async {
      await pumpApp(tester);
      await openTasks(tester);

      await openTask(tester, 'T-over');

      expect(find.byType(TaskDetailPage), findsOneWidget);
      final router = GoRouter.of(tester.element(find.byType(TaskDetailPage)));
      expect(router.state.matchedLocation, '/tasks/T-over');
      expect(
        tester.widget<NavigationBar>(find.byType(NavigationBar)).selectedIndex,
        1,
      );

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byType(TasksPage), findsOneWidget);
    });
  });

  group('detail', () {
    testWidgets('shows the task with the due flag judged in company time', (
      tester,
    ) async {
      await pumpApp(tester);
      await openTasks(tester);
      await openTask(tester, 'T-over');

      expect(find.text('Fix the pump'), findsOneWidget);
      expect(find.text('Priority: Normal'), findsOneWidget);
      expect(find.text('3 Sep 2026 · Overdue'), findsOneWidget);
      expect(find.text('Fit-out'), findsOneWidget);
      expect(find.text('Grace Hopper'), findsOneWidget);
      expect(find.text('Both floors.'), findsOneWidget);
      expect(find.byKey(const Key('task-completed-at')), findsNothing);
    });

    testWidgets(
      'a row opens with its content at once while the task reloads quietly',
      (tester) async {
        final gate = Completer<http.Response>();
        await pumpApp(tester);
        await openTasks(tester);
        onShow = (_) => gate.future;

        await tester.tap(row('T-over'));
        await tester.pumpAndSettle();

        expect(find.text('Fix the pump'), findsOneWidget);
        expect(find.byType(CircularProgressIndicator), findsNothing);

        gate.complete(
          jsonResponse({
            'data': {...store['T-over']!, 'title': 'Fix the main pump'},
          }),
        );
        await tester.pumpAndSettle();
        expect(find.text('Fix the main pump'), findsOneWidget);
      },
    );

    testWidgets('optional fields read clearly when missing', (tester) async {
      await pumpApp(tester);
      await openTasks(tester);
      await openTask(tester, 'T-none');

      expect(
        find.descendant(
          of: find.byKey(const Key('task-due')),
          matching: find.text('Not set'),
        ),
        findsOneWidget,
      );
      expect(find.text('No description.'), findsOneWidget);
    });

    testWidgets(
      'offers To do, In progress, Blocked and Completed — never Cancelled (R-4)',
      (tester) async {
        await pumpApp(tester);
        await openTasks(tester);
        await openTask(tester, 'T-today');

        final control = find.byKey(const Key('task-status-control'));
        for (final label in ['To do', 'In progress', 'Blocked', 'Completed']) {
          expect(
            find.descendant(of: control, matching: find.text(label)),
            findsOneWidget,
          );
        }
        expect(
          find.descendant(of: control, matching: find.text('Cancelled')),
          findsNothing,
        );
        expect(
          tester
              .widget<ChoiceChip>(find.byKey(const Key('task-set-todo')))
              .selected,
          isTrue,
        );
      },
    );

    testWidgets('a cancelled task shows its status with no control', (
      tester,
    ) async {
      await pumpApp(tester);
      await openTasks(tester);
      await selectDone(tester);
      await openTask(tester, 'T-cancel');

      expect(find.byKey(const Key('task-status-read-only')), findsOneWidget);
      expect(find.byKey(const Key('task-status-control')), findsNothing);
      expect(find.byType(ChoiceChip), findsNothing);
      expect(find.text('Cancelled'), findsOneWidget);
    });

    testWidgets(
      'a status change is confirmed, not optimistic, then shown with a notice',
      (tester) async {
        final gate = Completer<http.Response>();
        onPatch = (request) => gate.future;
        await pumpApp(tester);
        await openTasks(tester);
        await openTask(tester, 'T-today');

        await tester.tap(find.byKey(const Key('task-set-in_progress')));
        await tester.pump();

        expect(find.byKey(const Key('task-saving')), findsOneWidget);
        ChoiceChip chip(String s) =>
            tester.widget<ChoiceChip>(find.byKey(Key('task-set-$s')));
        expect(chip('todo').selected, isTrue);
        expect(chip('in_progress').selected, isFalse);
        expect(chip('blocked').onSelected, isNull, reason: 'disabled');

        gate.complete(
          jsonResponse({
            'data': {...store['T-today']!, 'status': 'in_progress'},
          }),
        );
        await tester.pumpAndSettle();

        expect(find.byKey(const Key('task-saving')), findsNothing);
        expect(chip('in_progress').selected, isTrue);
        expect(find.text('Status changed to In progress.'), findsOneWidget);
        final patches = requests.where((r) => r.method == 'PATCH');
        expect(patches, hasLength(1));
        expect(jsonDecode(patches.single.body), {'status': 'in_progress'});
      },
    );

    testWidgets(
      'completing a task moves it to Done and refreshes the lists and Home',
      (tester) async {
        await pumpApp(tester);
        expect(homeRequests, 1);
        await openTasks(tester);
        await openTask(tester, 'T-over');

        await tester.tap(find.byKey(const Key('task-set-completed')));
        await tester.pumpAndSettle();
        expect(find.byKey(const Key('task-completed-at')), findsOneWidget);
        expect(find.text('3 Sep 2026'), findsOneWidget, reason: 'no flag');

        await tester.pageBack();
        await tester.pumpAndSettle();

        expect(row('T-over'), findsNothing);
        expect(row('T-today'), findsOneWidget);
        expect(homeRequests, 2, reason: 'Home refreshed after the change');

        await selectDone(tester);
        expect(row('T-over'), findsOneWidget);

        // Reopen it from Done: it returns to Open.
        await openTask(tester, 'T-over');
        await tester.tap(find.byKey(const Key('task-set-todo')));
        await tester.pumpAndSettle();
        await tester.pageBack();
        await tester.pumpAndSettle();

        expect(row('T-over'), findsNothing);
        await tester.tap(find.text('Open'));
        await tester.pumpAndSettle();
        expect(inRow('T-over', find.text('Overdue · 3 Sep')), findsOneWidget);
      },
    );

    testWidgets(
      'a 403 shows the server message, reloads, and locks the control',
      (tester) async {
        onPatch = (_) async => jsonError(
          403,
          'You may only update the status of a task assigned to you.',
        );
        await pumpApp(tester);
        await openTasks(tester);
        await openTask(tester, 'T-later');
        final getsBefore = requests
            .where((r) => r.method == 'GET' && r.url.path.endsWith('/T-later'))
            .length;

        await tester.tap(find.byKey(const Key('task-set-blocked')));
        await tester.pumpAndSettle();

        expect(
          find.text(
            'You may only update the status of a task assigned to you.',
          ),
          findsOneWidget,
        );
        expect(
          requests
              .where(
                (r) => r.method == 'GET' && r.url.path.endsWith('/T-later'),
              )
              .length,
          getsBefore + 1,
        );
        expect(
          tester
              .widget<ChoiceChip>(find.byKey(const Key('task-set-blocked')))
              .onSelected,
          isNull,
        );
        expect(auth.status, AuthStatus.authenticated);

        await tester.pageBack();
        await tester.pumpAndSettle();
        expect(row('T-later'), findsOneWidget, reason: 'server still lists it');
      },
    );

    testWidgets(
      'offline, a change fails clearly, keeps the old status, and works after reconnecting',
      (tester) async {
        onPatch = (_) async => throw const NetworkFailure();
        await pumpApp(tester);
        await openTasks(tester);
        await openTask(tester, 'T-today');

        await tester.tap(find.byKey(const Key('task-set-blocked')));
        await tester.pumpAndSettle();

        expect(
          find.text(
            "Couldn't save the change. Check your connection and try again.",
          ),
          findsOneWidget,
        );
        expect(
          tester
              .widget<ChoiceChip>(find.byKey(const Key('task-set-todo')))
              .selected,
          isTrue,
        );

        onPatch = null;
        await tester.tap(find.byKey(const Key('task-set-blocked')));
        await tester.pumpAndSettle();

        expect(find.byKey(const Key('task-save-message')), findsNothing);
        expect(
          tester
              .widget<ChoiceChip>(find.byKey(const Key('task-set-blocked')))
              .selected,
          isTrue,
        );
      },
    );

    testWidgets('a 422 shows the server message', (tester) async {
      onPatch = (_) async => http.Response(
        jsonEncode({
          'message': 'The given data was invalid.',
          'errors': {
            'status': ['The selected status is invalid.'],
          },
        }),
        422,
      );
      await pumpApp(tester);
      await openTasks(tester);
      await openTask(tester, 'T-today');

      await tester.tap(find.byKey(const Key('task-set-completed')));
      await tester.pumpAndSettle();

      expect(find.text('The selected status is invalid.'), findsOneWidget);
    });

    testWidgets('a revoked token during a save returns to Login', (
      tester,
    ) async {
      onPatch = (_) async => jsonError(401);
      await pumpApp(tester);
      await openTasks(tester);
      await openTask(tester, 'T-today');

      await tester.tap(find.byKey(const Key('task-set-completed')));
      await tester.pumpAndSettle();

      expect(auth.status, AuthStatus.unauthenticated);
      expect(find.byType(TaskDetailPage), findsNothing);
      expect(
        find.text('Your session has ended. Please sign in again.'),
        findsOneWidget,
      );
    });

    testWidgets(
      "opened from Home, the due flag uses Home's company day, not the device clock",
      (tester) async {
        store['01TASK00000000000000000001'] = task(
          '01TASK00000000000000000001',
          'Replace filter unit',
          due: today,
        );
        await pumpApp(tester);

        await tester.tap(find.text('Replace filter unit'));
        await tester.pumpAndSettle();

        expect(find.byType(TaskDetailPage), findsOneWidget);
        expect(find.text('24 Sep 2026 · Due today'), findsOneWidget);
      },
    );

    testWidgets('a failed load with nothing to show offers Try again', (
      tester,
    ) async {
      onShow = (_) async => jsonError(404);
      await pumpApp(tester);

      // Home's Today task row opens the detail without a list item.
      await tester.tap(find.text('Replace filter unit'));
      await tester.pumpAndSettle();

      expect(find.text('This task is no longer available.'), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
    });
  });

  group('appearance', () {
    for (final (label, brightness) in [
      ('light', Brightness.light),
      ('dark', Brightness.dark),
    ]) {
      testWidgets(
        'list and detail render without overflow in $label mode at 200% text',
        (tester) async {
          tester.platformDispatcher.platformBrightnessTestValue = brightness;
          tester.platformDispatcher.textScaleFactorTestValue = 2.0;
          addTearDown(tester.platformDispatcher.clearAllTestValues);
          store['T-long'] = task(
            'T-long',
            'A very long task title that keeps going well past one line '
                'to check wrapping',
            due: '2026-09-01',
          );

          await pumpApp(tester);
          await openTasks(tester);
          expect(tester.takeException(), isNull);
          expect(row('T-long'), findsOneWidget);

          await openTask(tester, 'T-over');
          expect(tester.takeException(), isNull);
          expect(find.byKey(const Key('task-status-control')), findsOneWidget);
          expect(
            Theme.of(tester.element(find.byType(TaskDetailPage))).brightness,
            brightness,
          );
        },
      );
    }
  });
}

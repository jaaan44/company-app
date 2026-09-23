import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/presentation/home_page.dart';
import 'package:mobile/features/shell/presentation/placeholder_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart';

/// Phase 27 Gate 3 — the employee Home screen through the real app
/// (`CompanyApp`, router, shell, `AuthController`, Gate 2 `ApiClient`),
/// against a scripted `/me/home` (docs/phases/V1_PHASE_27_DEFINITION.md §8).
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late int homeRequests;

  Future<void> pumpHome(
    WidgetTester tester, {
    FutureOr<http.Response> Function()? respond,
    bool settle = true,
    bool tallViewport = true,
  }) async {
    if (tallViewport) {
      // ListView builds lazily; a tall viewport lays out every section so
      // the finders can see them.
      tester.view.physicalSize =
          const Size(800, 2400) * tester.view.devicePixelRatio;
      addTearDown(tester.view.resetPhysicalSize);
    }
    backend = FakeBackend();
    homeRequests = 0;
    backend.onApi = (request) async {
      expect(request.url.path, endsWith('/me/home'));
      homeRequests++;

      return respond == null ? homeResponse() : await respond();
    };
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));

    await tester.pumpWidget(
      CompanyApp(
        authController: auth,
        homeApiClient: homeClientFor(backend, auth),
      ),
    );
    if (settle) {
      await tester.pumpAndSettle();
    }
  }

  Finder inSection(String key, Finder matching) =>
      find.descendant(of: find.byKey(Key(key)), matching: matching);

  /// Home's own scrollable (the ListView inside the RefreshIndicator) —
  /// the reliable target for scroll and pull gestures, unlike a child
  /// section whose centre may be off-screen or covered.
  Finder homeScrollable() => find
      .descendant(of: find.byType(HomePage), matching: find.byType(Scrollable))
      .first;

  // A gesture that misses its target must fail the test, not just warn —
  // otherwise a skipped scroll can pass silently (seen at 200% text).
  setUpAll(() => WidgetController.hitTestWarningShouldBeFatal = true);
  tearDownAll(() => WidgetController.hitTestWarningShouldBeFatal = false);

  group('populated Home', () {
    testWidgets('shows the sections in the approved order', (tester) async {
      await pumpHome(tester);

      final order = [
        'home-greeting',
        'home-today',
        'home-attention',
        'home-announcements',
      ].map((k) => tester.getTopLeft(find.byKey(Key(k))).dy).toList();
      expect(order, [...order]..sort());
      expect(find.widgetWithText(AppBar, 'Home'), findsOneWidget);
    });

    testWidgets('greets by preferred/first name with position and department', (
      tester,
    ) async {
      await pumpHome(
        tester,
        respond: () => homeResponse(
          homeDataJson(
            staff: staffJson(preferredName: 'Addie', team: 'North team'),
          ),
        ),
      );

      expect(find.text('Hello, Addie'), findsOneWidget);
      expect(find.text('Field Technician · Operations'), findsOneWidget);
      expect(find.text('North team'), findsOneWidget);
      expect(find.textContaining('Good morning'), findsNothing);
    });

    testWidgets('omits missing profile parts gracefully', (tester) async {
      await pumpHome(
        tester,
        respond: () => homeResponse(
          homeDataJson(
            staff: staffJson(position: null, department: 'Operations'),
          ),
        ),
      );

      expect(find.text('Hello, Ada'), findsOneWidget);
      expect(find.text('Operations'), findsOneWidget);
    });

    testWidgets(
      'Today shows schedule entries and tasks in server order, in company time',
      (tester) async {
        await pumpHome(tester);

        expect(find.text('Today · Thu 24 Sep'), findsOneWidget);
        final entry = find.text('Site visit — Acme');
        final task = find.text('Replace filter unit');
        expect(entry, findsOneWidget);
        expect(task, findsOneWidget);
        expect(
          tester.getTopLeft(entry).dy,
          lessThan(tester.getTopLeft(task).dy),
        );
        // 00:00–01:30 UTC shown at the company's +09:00 offset.
        expect(find.text('Client visit · 09:00 – 10:30'), findsOneWidget);
        expect(find.text('Task due · In progress · All day'), findsOneWidget);
        expect(
          inSection('home-today', find.byIcon(Icons.event)),
          findsOneWidget,
        );
        expect(
          inSection('home-today', find.byIcon(Icons.task_alt)),
          findsOneWidget,
        );
      },
    );

    testWidgets('Today preserves server order even when it looks unsorted', (
      tester,
    ) async {
      await pumpHome(
        tester,
        respond: () => homeResponse(
          homeDataJson(
            todayItems: [
              taskItemJson(publicId: '01T2', title: 'Zulu task'),
              scheduleItemJson(
                publicId: '01E1',
                title: 'Alpha meeting',
                activityType: 'meeting',
              ),
            ],
          ),
        ),
      );

      expect(
        tester.getTopLeft(find.text('Zulu task')).dy,
        lessThan(tester.getTopLeft(find.text('Alpha meeting')).dy),
      );
    });

    testWidgets(
      'Today shows "+N more today" when total_count exceeds the items',
      (tester) async {
        await pumpHome(
          tester,
          respond: () => homeResponse(homeDataJson(todayTotal: 7)),
        );

        expect(find.text('+5 more today'), findsOneWidget);
      },
    );

    testWidgets('Today empty state', (tester) async {
      await pumpHome(
        tester,
        respond: () => homeResponse(homeDataJson(todayItems: [])),
      );

      expect(find.text('Nothing scheduled for you today.'), findsOneWidget);
      expect(find.textContaining('more today'), findsNothing);
    });

    testWidgets('Needs attention shows the server counts', (tester) async {
      await pumpHome(tester);

      expect(inSection('home-tile-tasks', find.text('4')), findsOneWidget);
      expect(
        inSection('home-tile-tasks', find.text('1 overdue')),
        findsOneWidget,
      );
      expect(
        inSection('home-tile-tasks', find.text('2 due today')),
        findsOneWidget,
      );
      expect(inSection('home-tile-messages', find.text('3')), findsOneWidget);
      expect(
        inSection('home-tile-notifications', find.text('5')),
        findsOneWidget,
      );

      final overdue = tester.widget<Text>(
        inSection('home-tile-tasks', find.text('1 overdue')),
      );
      final context = tester.element(find.byType(HomePage));
      expect(overdue.style?.color, Theme.of(context).colorScheme.error);
    });

    testWidgets('zero counts stay visible with calm wording', (tester) async {
      await pumpHome(
        tester,
        respond: () => homeResponse(
          homeDataJson(
            tasks: {'open_count': 0, 'overdue_count': 0, 'due_today_count': 0},
            unreadMessages: 0,
            unreadNotifications: 0,
          ),
        ),
      );

      expect(
        inSection('home-tile-tasks', find.text('0 overdue')),
        findsOneWidget,
      );
      expect(find.text('No unread messages'), findsOneWidget);
      expect(find.text('No unread notifications'), findsOneWidget);
      final overdue = tester.widget<Text>(
        inSection('home-tile-tasks', find.text('0 overdue')),
      );
      final context = tester.element(find.byType(HomePage));
      expect(overdue.style?.color, isNot(Theme.of(context).colorScheme.error));
    });

    testWidgets(
      'Latest announcements shows titles and company-time dates only',
      (tester) async {
        await pumpHome(
          tester,
          respond: () => homeResponse(
            homeDataJson(
              announcements: [
                announcementJson(
                  publicId: '01A1',
                  title: 'Office closed Friday',
                ),
                // 20:00 UTC on the 21st is the 22nd at +09:00.
                announcementJson(
                  publicId: '01A2',
                  title: 'New parking rules',
                  publishedAt: '2026-09-21T20:00:00+00:00',
                ),
              ],
            ),
          ),
        );

        expect(
          inSection('home-announcements', find.text('Office closed Friday')),
          findsOneWidget,
        );
        expect(
          inSection('home-announcements', find.text('New parking rules')),
          findsOneWidget,
        );
        expect(
          inSection('home-announcements', find.text('22 Sep 2026')),
          findsNWidgets(2),
        );
        expect(find.textContaining('Acknowledge'), findsNothing);
      },
    );

    testWidgets('announcements empty state', (tester) async {
      await pumpHome(
        tester,
        respond: () => homeResponse(homeDataJson(announcements: [])),
      );

      expect(find.text('No announcements yet.'), findsOneWidget);
    });
  });

  group('no employee profile', () {
    testWidgets('shows the explanation and only the always-available section', (
      tester,
    ) async {
      await pumpHome(
        tester,
        respond: () =>
            homeResponse(homeDataJson(staff: null, unreadNotifications: 2)),
      );

      expect(find.text('Hello, Ada Lovelace'), findsOneWidget);
      expect(
        find.text(
          "Your account isn't linked to an employee profile, so some sections aren't available.",
        ),
        findsOneWidget,
      );
      expect(find.byKey(const Key('home-today')), findsNothing);
      expect(find.byKey(const Key('home-announcements')), findsNothing);
      expect(find.byKey(const Key('home-tile-tasks')), findsNothing);
      expect(find.byKey(const Key('home-tile-messages')), findsNothing);
      expect(
        inSection('home-tile-notifications', find.text('2')),
        findsOneWidget,
      );
      // Not an error, not a logout.
      expect(find.text('Try again'), findsNothing);
      expect(find.byType(LoginPage), findsNothing);
      expect(auth.status, AuthStatus.authenticated);
    });
  });

  group('non-interactive content (R-1)', () {
    testWidgets(
      'no row, tile or card has a tap handler or navigation affordance',
      (tester) async {
        await pumpHome(
          tester,
          respond: () => homeResponse(homeDataJson(todayTotal: 7)),
        );

        for (final section in [
          'home-greeting',
          'home-today',
          'home-attention',
          'home-announcements',
        ]) {
          for (final type in [
            InkWell,
            GestureDetector,
            ListTile,
            TextButton,
            FilledButton,
            OutlinedButton,
            IconButton,
          ]) {
            expect(
              inSection(section, find.byType(type)),
              findsNothing,
              reason: '$type in $section',
            );
          }
        }
        for (final icon in [
          Icons.chevron_right,
          Icons.arrow_forward,
          Icons.arrow_forward_ios,
          Icons.open_in_new,
        ]) {
          expect(find.byIcon(icon), findsNothing);
        }
      },
    );

    testWidgets(
      'tapping Today rows, tiles and announcements navigates nowhere',
      (tester) async {
        await pumpHome(
          tester,
          respond: () => homeResponse(homeDataJson(todayTotal: 7)),
        );
        final router = GoRouter.of(tester.element(find.byType(HomePage)));

        for (final target in [
          find.text('Site visit — Acme'),
          find.text('Replace filter unit'),
          find.byKey(const Key('home-tile-tasks')),
          find.byKey(const Key('home-tile-messages')),
          find.byKey(const Key('home-tile-notifications')),
          find.text('Office closed Friday'),
          find.text('+5 more today'),
        ]) {
          await tester.ensureVisible(target);
          await tester.tap(target, warnIfMissed: false);
          await tester.pumpAndSettle();

          expect(router.state.matchedLocation, '/home');
          expect(
            tester
                .widget<NavigationBar>(find.byType(NavigationBar))
                .selectedIndex,
            0,
          );
          expect(find.byType(HomePage), findsOneWidget);
        }
        expect(homeRequests, 1);
      },
    );

    testWidgets('the five shell tabs themselves still navigate', (
      tester,
    ) async {
      await pumpHome(tester);

      await tester.tap(find.widgetWithText(NavigationDestination, 'Tasks'));
      await tester.pumpAndSettle();
      expect(find.byType(TasksPlaceholderPage), findsOneWidget);

      await tester.tap(find.widgetWithText(NavigationDestination, 'Home'));
      await tester.pumpAndSettle();
      expect(find.byType(HomePage), findsOneWidget);
    });
  });

  group('loading, error, retry, refresh', () {
    testWidgets('shows a centered spinner until the first response', (
      tester,
    ) async {
      final gate = Completer<http.Response>();
      await pumpHome(tester, respond: () => gate.future, settle: false);
      await tester.pump();
      await tester.pump();

      expect(find.byType(HomePage), findsOneWidget);
      expect(
        find.descendant(
          of: find.byType(HomePage),
          matching: find.byType(CircularProgressIndicator),
        ),
        findsOneWidget,
      );
      expect(find.byKey(const Key('home-greeting')), findsNothing);

      gate.complete(homeResponse());
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('home-greeting')), findsOneWidget);
    });

    testWidgets('a network failure shows the error state; Try again reloads', (
      tester,
    ) async {
      var fail = true;
      await pumpHome(
        tester,
        respond: () => fail ? throw const NetworkFailure() : homeResponse(),
      );

      expect(
        find.text("Couldn't load your Home. Check your connection."),
        findsOneWidget,
      );
      expect(find.widgetWithText(FilledButton, 'Try again'), findsOneWidget);
      expect(find.textContaining('Exception'), findsNothing);
      expect(
        find.ancestor(
          of: find.text("Couldn't load your Home. Check your connection."),
          matching: find.byWidgetPredicate(
            (w) => w is Semantics && w.properties.liveRegion == true,
          ),
        ),
        findsOneWidget,
      );

      fail = false;
      await tester.tap(find.widgetWithText(FilledButton, 'Try again'));
      await tester.pumpAndSettle();

      expect(homeRequests, 2);
      expect(find.text('Hello, Ada'), findsOneWidget);
      expect(find.text('Try again'), findsNothing);
    });

    testWidgets(
      'a server failure shows a generic message, never technical detail',
      (tester) async {
        await pumpHome(
          tester,
          respond: () => jsonError(500, 'SQLSTATE[HY000] boom'),
        );

        expect(
          find.text('Something went wrong loading your Home.'),
          findsOneWidget,
        );
        expect(find.textContaining('SQLSTATE'), findsNothing);
      },
    );

    testWidgets('pull-to-refresh reloads and keeps the content on screen', (
      tester,
    ) async {
      var messages = 3;
      await pumpHome(
        tester,
        respond: () => homeResponse(homeDataJson(unreadMessages: messages)),
      );
      messages = 8;

      await tester.fling(
        homeScrollable(),
        // RefreshIndicator arms at 25% of the (tall, 2400px) viewport.
        const Offset(0, 1000),
        1000,
      );
      await tester.pump();
      // The content stays up during the refresh — no full-screen spinner.
      expect(find.byKey(const Key('home-greeting')), findsOneWidget);
      await tester.pumpAndSettle();

      expect(homeRequests, 2);
      expect(inSection('home-tile-messages', find.text('8')), findsOneWidget);
    });

    testWidgets('a failed refresh keeps earlier data and says so', (
      tester,
    ) async {
      var fail = false;
      await pumpHome(
        tester,
        respond: () => fail ? jsonError(503) : homeResponse(),
      );
      fail = true;

      await tester.fling(
        homeScrollable(),
        // RefreshIndicator arms at 25% of the (tall, 2400px) viewport.
        const Offset(0, 1000),
        1000,
      );
      await tester.pumpAndSettle();

      expect(
        find.text("Couldn't refresh. Showing earlier information."),
        findsOneWidget,
      );
      expect(find.text('Hello, Ada'), findsOneWidget);
      expect(find.text('Try again'), findsNothing);
    });

    testWidgets('rebuilds, theme changes and tab switches do not re-fetch', (
      tester,
    ) async {
      await pumpHome(tester);

      tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(NavigationDestination, 'Messages'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(NavigationDestination, 'Home'));
      await tester.pumpAndSettle();
      // Force genuine rebuilds of the page itself.
      for (var i = 0; i < 3; i++) {
        tester.element(find.byType(HomePage)).markNeedsBuild();
        await tester.pumpAndSettle();
      }

      expect(homeRequests, 1);
    });
  });

  group('session integration (Gate 2)', () {
    testWidgets('a /me/home 401 leaves the shell for Login with the notice', (
      tester,
    ) async {
      await pumpHome(tester, respond: () => jsonError(401));

      expect(find.byType(LoginPage), findsOneWidget);
      expect(find.text(AuthController.sessionEndedNotice), findsOneWidget);
      expect(find.byType(HomePage, skipOffstage: false), findsNothing);
    });

    testWidgets(
      'an ordinary /me/home 403 keeps the employee signed in with Try again',
      (tester) async {
        await pumpHome(
          tester,
          respond: () => jsonError(403, 'Not allowed here.'),
        );

        expect(backend.meCalls, 2); // bootstrap + the one Gate 2 re-check
        expect(find.byType(HomePage), findsOneWidget);
        expect(find.text('Not allowed here.'), findsOneWidget);
        expect(find.widgetWithText(FilledButton, 'Try again'), findsOneWidget);
        expect(find.text(AuthController.sessionEndedNotice), findsNothing);
        expect(auth.status, AuthStatus.authenticated);
      },
    );

    testWidgets('logout remains available from Home and uses Gate 2 logout', (
      tester,
    ) async {
      await pumpHome(tester);

      expect(find.byTooltip('Log out'), findsOneWidget);
      await tester.tap(find.byTooltip('Log out'));
      await tester.pumpAndSettle();

      expect(find.byType(LoginPage), findsOneWidget);
      expect(backend.logoutCalls, 1);
      expect(find.text(AuthController.sessionEndedNotice), findsNothing);
    });
  });

  group('theme, text scale and accessibility', () {
    Map<String, dynamic> longContent() => homeDataJson(
      staff: staffJson(
        firstName: 'Maximiliana-Alexandrina Wolfeschlegelsteinhausenberger',
        position:
            'Senior Principal Field Operations and Maintenance Technician',
        department:
            'Regional Infrastructure and Facilities Operations Department',
        team: 'Northern Coastal Emergency Response and Night Maintenance Team',
      ),
      todayTotal: 12,
      todayItems: [
        scheduleItemJson(
          title: 'Quarterly on-site inspection of all refrigeration, ventilation and fire-suppression systems at the harbour warehouse',
        ),
        taskItemJson(
          title: 'Replace the corroded coolant manifold and document every serial number in the service report',
          status: 'blocked',
        ),
      ],
      tasks: {'open_count': 1234, 'overdue_count': 567, 'due_today_count': 89},
      unreadMessages: 9999,
      unreadNotifications: 12345,
      announcements: [
        announcementJson(
          title: 'Important: new company-wide health, safety and environmental procedures take effect from the first of next month for every site',
        ),
      ],
    );

    for (final (label, brightness, scale, size)
        in <(String, Brightness, double, Size)>[
          ('light, phone', Brightness.light, 1.0, const Size(360, 740)),
          ('dark, phone', Brightness.dark, 1.0, const Size(360, 740)),
          (
            'light, 200% text, phone',
            Brightness.light,
            2.0,
            const Size(360, 740),
          ),
          (
            'dark, 200% text, phone',
            Brightness.dark,
            2.0,
            const Size(360, 740),
          ),
          (
            'light, 200% text, tablet',
            Brightness.light,
            2.0,
            const Size(1024, 768),
          ),
        ]) {
      testWidgets('$label: long content renders without overflow', (
        tester,
      ) async {
        tester.view.physicalSize = size * tester.view.devicePixelRatio;
        addTearDown(tester.view.resetPhysicalSize);
        tester.platformDispatcher.platformBrightnessTestValue = brightness;
        addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
        tester.platformDispatcher.textScaleFactorTestValue = scale;
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

        await pumpHome(
          tester,
          respond: () => homeResponse(longContent()),
          tallViewport: false,
        );

        expect(tester.takeException(), isNull);
        final context = tester.element(find.byType(HomePage));
        expect(Theme.of(context).brightness, brightness);
        expect(find.textContaining('Hello, Maximiliana'), findsOneWidget);
        // Walk down the page section by section. scrollUntilVisible fails
        // the test if a section is never reached, so each lower section is
        // proven to be built, laid out and on screen at this size/scale.
        for (final key in [
          'home-today',
          'home-tile-tasks',
          'home-tile-messages',
          'home-tile-notifications',
          'home-announcements',
        ]) {
          await tester.scrollUntilVisible(
            find.byKey(Key(key)),
            200,
            scrollable: homeScrollable(),
          );
          await tester.pumpAndSettle();
          expect(
            find.byKey(Key(key)).hitTestable(),
            findsWidgets,
            reason: '$key reached',
          );
          expect(tester.takeException(), isNull, reason: 'after $key');
        }
        // The last announcement row itself is on screen too.
        expect(
          find.textContaining('Important: new company-wide').hitTestable(),
          findsOneWidget,
        );
        expect(tester.takeException(), isNull);
      });
    }

    testWidgets(
      'tiles expose one combined label each; controls meet tap-target guidelines',
      (tester) async {
        final handle = tester.ensureSemantics();
        await pumpHome(tester);

        expect(
          find.bySemanticsLabel('My tasks: 4 open, 1 overdue, 2 due today'),
          findsOneWidget,
        );
        expect(find.bySemanticsLabel('3 unread messages'), findsOneWidget);
        expect(find.bySemanticsLabel('5 unread notifications'), findsOneWidget);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      },
    );

    testWidgets('Try again meets tap-target and label guidelines', (
      tester,
    ) async {
      final handle = tester.ensureSemantics();
      await pumpHome(tester, respond: () => throw const NetworkFailure());

      expect(
        tester.getSize(find.widgetWithText(FilledButton, 'Try again')).height,
        greaterThanOrEqualTo(48),
      );
      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      handle.dispose();
    });
  });
}

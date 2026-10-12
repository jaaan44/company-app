import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/leave/presentation/leave_form_page.dart';
import 'package:mobile/features/leave/presentation/leave_page.dart';
import 'package:mobile/features/leave/presentation/leave_request_page.dart';
import 'package:mobile/features/leave/presentation/team_leave_page.dart';
import 'package:mobile/features/people/presentation/more_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart' show homeClientFor, homeResponse;
import '../../support/leave_fixtures.dart';
import '../../support/my_status_fixtures.dart' show myStatusClientFor;
import '../../support/people_fixtures.dart' show jsonResponse;
import '../../support/schedule_fixtures.dart'
    show myScheduleJson, scheduleClientFor;

/// Phase 31 Gate 3 — More → Leave, Request leave, a request's detail and
/// Team requests through the real app (`CompanyApp`, router, shell,
/// `ApiClient`) against an in-memory leave API
/// (docs/phases/V1_PHASE_31_DEFINITION.md, R-1…R-11). The company day is
/// Wed 14 Oct 2026 in Manila.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late FakeLeaveServer server;
  late List<http.Request> requests;

  Future<http.Response> route(http.Request r) async {
    requests.add(r);
    final path = r.url.path;
    if (path.endsWith('/me/home')) {
      return homeResponse();
    }
    if (path.endsWith('/me/status')) {
      return jsonResponse(pageBody([]));
    }
    if (path.endsWith('/me/schedule')) {
      return jsonResponse(myScheduleJson([]));
    }

    return server.handle(r);
  }

  setUp(() {
    requests = [];
    server = FakeLeaveServer();
  });

  setUpAll(() => WidgetController.hitTestWarningShouldBeFatal = true);
  tearDownAll(() => WidgetController.hitTestWarningShouldBeFatal = false);

  Future<void> pumpApp(WidgetTester tester, {bool tall = true}) async {
    if (tall) {
      tester.view.physicalSize =
          const Size(800, 2400) * tester.view.devicePixelRatio;
      addTearDown(tester.view.resetPhysicalSize);
    }
    backend = FakeBackend()..onApi = route;
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));

    await tester.pumpWidget(
      CompanyApp(
        authController: auth,
        homeApiClient: homeClientFor(backend, auth),
        myStatusApiClient: myStatusClientFor(backend, auth),
        scheduleApiClient: scheduleClientFor(backend, auth),
        leaveApiClient: leaveClientFor(backend, auth),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> tapKey(WidgetTester tester, String key) async {
    await tester.ensureVisible(find.byKey(Key(key)));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key(key)));
    await tester.pumpAndSettle();
  }

  Future<void> openLeave(WidgetTester tester) async {
    await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
    await tester.pumpAndSettle();
    // More builds lazily: on a small screen the row may be below the fold.
    await tester.scrollUntilVisible(
      find.byKey(const Key('more-leave')),
      100,
      scrollable: find
          .descendant(
            of: find.byType(MorePage),
            matching: find.byType(Scrollable),
          )
          .first,
    );
    await tapKey(tester, 'more-leave');
  }

  Finder inKey(String key, Finder matching) =>
      find.descendant(of: find.byKey(Key(key)), matching: matching);

  List<http.Request> posts() => [
    for (final r in requests)
      if (r.method == 'POST') r,
  ];

  void makeManager() {
    server
      ..canReview = true
      ..directReports = 2
      ..team = [
        leaveJson(
          publicId: 'T-LATER',
          staff: person('S2', 'Grace Hopper'),
          start: '2026-11-02',
          end: '2026-11-03',
          reason: 'Conference.',
        ),
        leaveJson(
          publicId: 'T-SOON',
          staff: person('S3', 'Alan Turing'),
          start: '2026-10-19',
          end: '2026-10-19',
          reason: 'Appointment.',
        ),
        leaveJson(
          publicId: 'T-DONE',
          staff: person('S2', 'Grace Hopper'),
          status: 'approved',
          start: '2026-09-01',
          end: '2026-09-01',
        ),
      ];
  }

  group('Leave screen (R-1, R-8, R-9)', () {
    testWidgets('balances for the company year and my requests', (
      tester,
    ) async {
      server.mine = [
        leaveJson(),
        leaveJson(
          publicId: 'OLD',
          status: 'rejected',
          start: '2026-08-03',
          end: '2026-08-03',
        ),
      ];
      await pumpApp(tester);
      await openLeave(tester);

      expect(find.byType(LeavePage), findsOneWidget);
      expect(find.text('Balances 2026'), findsOneWidget);
      final annual = 'leave-balance-${annualType['public_id']}';
      expect(inKey(annual, find.text('Annual Leave')), findsOneWidget);
      expect(
        inKey(annual, find.text('10 days left of 15\n3 used · 2 pending')),
        findsOneWidget,
      );
      expect(
        inKey(
          'leave-balance-${unpaidType['public_id']}',
          find.text('Unpaid — no balance\n3 used · 2 pending'),
        ),
        findsOneWidget,
      );

      final row = 'leave-row-01J0LEAVE0000000000000AAAA';
      expect(
        inKey(row, find.text('Tue 20 Oct – Thu 22 Oct 2026 · 3 days')),
        findsOneWidget,
      );
      expect(inKey(row, find.text('Pending')), findsOneWidget);
      expect(inKey('leave-row-OLD', find.text('Rejected')), findsOneWidget);
      expect(
        inKey('leave-row-OLD', find.text('Mon 3 Aug 2026 · 1 day')),
        findsOneWidget,
      );
      // Not a reviewer: no Team requests.
      expect(find.byKey(const Key('leave-team')), findsNothing);
    });

    testWidgets('nothing requested yet', (tester) async {
      server.mine = [];
      await pumpApp(tester);
      await openLeave(tester);

      expect(find.text("You haven't requested leave yet."), findsOneWidget);
    });

    testWidgets('no profile: the message, and nothing else', (tester) async {
      server.override = (r) =>
          jsonError(403, 'No staff record is linked to this account.');
      await pumpApp(tester);
      await openLeave(tester);

      expect(find.byKey(const Key('leave-no-profile')), findsOneWidget);
      expect(find.byKey(const Key('leave-request')), findsNothing);
      // The session survives the 403.
      expect(find.byType(LeavePage), findsOneWidget);
    });

    testWidgets('offline: an error with Try again', (tester) async {
      server.override = (r) => throw const NetworkFailure();
      await pumpApp(tester);
      await openLeave(tester);

      expect(
        find.text("Couldn't load your leave. Check your connection."),
        findsOneWidget,
      );
      server.override = null;
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();
      expect(find.text('Balances 2026'), findsOneWidget);
    });

    testWidgets('more than a page of requests: Show more', (tester) async {
      server.mine = [for (var i = 0; i < 23; i++) leaveJson(publicId: 'R$i')];
      await pumpApp(tester);
      await openLeave(tester);

      expect(find.byKey(const Key('leave-row-R22')), findsNothing);
      await tapKey(tester, 'leave-load-more');
      expect(find.byKey(const Key('leave-row-R22')), findsOneWidget);
      expect(find.byKey(const Key('leave-load-more')), findsNothing);
    });
  });

  group('Request leave (R-5)', () {
    Future<void> openForm(WidgetTester tester) async {
      await pumpApp(tester);
      await openLeave(tester);
      await tapKey(tester, 'leave-request');
      expect(find.byType(LeaveFormPage), findsOneWidget);
    }

    Future<void> pickDay(WidgetTester tester, String field, String day) async {
      await tapKey(tester, field);
      await tester.tap(find.text(day));
      await tester.pumpAndSettle();
      await tester.tap(find.text('OK'));
      await tester.pumpAndSettle();
    }

    testWidgets('dates default to the company today', (tester) async {
      await openForm(tester);

      expect(
        inKey('leave-form-start', find.text('Wed 14 Oct 2026')),
        findsOneWidget,
      );
      expect(inKey('leave-form-start', find.text('Today')), findsOneWidget);
      expect(
        inKey('leave-form-end', find.text('Wed 14 Oct 2026')),
        findsOneWidget,
      );
      expect(find.text('1 calendar day (weekends included)'), findsOneWidget);
      // The form took the balances from the Leave screen: no new reads.
      expect(
        requests.where((r) => r.url.path.endsWith('/me/leave-balances')),
        hasLength(1),
      );
    });

    testWidgets('choose a paid type, dates and a reason; send once', (
      tester,
    ) async {
      await openForm(tester);

      await tapKey(tester, 'leave-form-type');
      expect(find.text('10 days left in 2026'), findsOneWidget);
      expect(find.text('Unpaid'), findsOneWidget);
      await tapKey(tester, 'leave-type-${annualType['public_id']}');
      expect(
        inKey('leave-form-type', find.text('10 days left in 2026')),
        findsOneWidget,
      );

      await pickDay(tester, 'leave-form-start', '16');
      await pickDay(tester, 'leave-form-end', '19');
      expect(find.text('4 calendar days (weekends included)'), findsOneWidget);
      await tester.enterText(
        find.byKey(const Key('leave-form-reason')),
        'Family trip.',
      );
      await tapKey(tester, 'leave-form-submit');

      expect(posts(), hasLength(1));
      expect(server.bodyOf(posts().single), {
        'leave_type_id': annualType['public_id'],
        'start_date': '2026-10-16',
        'end_date': '2026-10-19',
        'reason': 'Family trip.',
      });
      expect(find.text('Leave requested.'), findsOneWidget);
      // Back on Leave, which reloaded: the new request is at the top.
      expect(find.byType(LeavePage), findsOneWidget);
      expect(
        find.byKey(const Key('leave-row-01J0LEAVENEW00000000000AAA')),
        findsOneWidget,
      );
    });

    testWidgets('checked before sending; nothing is sent', (tester) async {
      await openForm(tester);
      await tester.enterText(find.byKey(const Key('leave-form-reason')), 'a');
      await tapKey(tester, 'leave-form-submit');

      expect(find.text('Choose a type of leave.'), findsOneWidget);
      expect(
        find.text('Give a reason (at least 3 characters).'),
        findsOneWidget,
      );
      expect(posts(), isEmpty);
    });

    testWidgets("the server's refusal is shown and the input stays", (
      tester,
    ) async {
      server.override = (r) => r.method == 'POST'
          ? jsonError(409, 'Insufficient leave balance: 1 day(s) remaining.')
          : null;
      await openForm(tester);
      await tapKey(tester, 'leave-form-type');
      await tapKey(tester, 'leave-type-${annualType['public_id']}');
      await tester.enterText(
        find.byKey(const Key('leave-form-reason')),
        'Long trip.',
      );
      await tapKey(tester, 'leave-form-submit');

      expect(
        inKey(
          'leave-form-error',
          find.text('Insufficient leave balance: 1 day(s) remaining.'),
        ),
        findsOneWidget,
      );
      expect(find.byType(LeaveFormPage), findsOneWidget);
      expect(find.text('Long trip.'), findsOneWidget);
    });

    testWidgets('leaving with something entered asks first', (tester) async {
      await openForm(tester);
      await tester.enterText(
        find.byKey(const Key('leave-form-reason')),
        'Maybe.',
      );
      await tester.pumpAndSettle();
      await tester.pageBack();
      await tester.pumpAndSettle();

      expect(find.text('Discard this request?'), findsOneWidget);
      await tester.tap(find.text('Keep editing'));
      await tester.pumpAndSettle();
      expect(find.byType(LeaveFormPage), findsOneWidget);

      await tester.pageBack();
      await tester.pumpAndSettle();
      await tapKey(tester, 'leave-discard-confirm');
      expect(find.byType(LeavePage), findsOneWidget);
      expect(posts(), isEmpty);
    });

    testWidgets('leaving an untouched form asks nothing', (tester) async {
      await openForm(tester);
      await tester.pageBack();
      await tester.pumpAndSettle();

      expect(find.byType(LeavePage), findsOneWidget);
    });
  });

  group('My request (R-6)', () {
    testWidgets('details, history, and cancel with a reason', (tester) async {
      await pumpApp(tester);
      await openLeave(tester);
      await tapKey(tester, 'leave-row-01J0LEAVE0000000000000AAAA');

      expect(find.byType(LeaveRequestPage), findsOneWidget);
      expect(find.text('Annual Leave'), findsOneWidget);
      expect(find.text('Paid'), findsOneWidget);
      expect(find.text('Tue 20 Oct – Thu 22 Oct 2026'), findsOneWidget);
      expect(find.text('3 calendar days (weekends included)'), findsOneWidget);
      expect(find.text('Family trip.'), findsOneWidget);
      // 01:00 UTC on 10 Oct is 09:00 in Manila.
      expect(
        inKey(
          'leave-history-0',
          find.text('Requested by Ada Lovelace · Sat 10 Oct 09:00'),
        ),
        findsOneWidget,
      );

      await tapKey(tester, 'leave-cancel');
      expect(find.text('Cancel this request?'), findsOneWidget);
      await tester.enterText(
        find.byKey(const Key('leave-dialog-text')),
        'Plans changed.',
      );
      await tapKey(tester, 'leave-cancel-confirm');

      expect(posts(), hasLength(1));
      expect(server.bodyOf(posts().single), {'reason': 'Plans changed.'});
      expect(find.text('Request cancelled.'), findsOneWidget);
      expect(
        inKey('leave-detail', find.byKey(const Key('leave-status-cancelled'))),
        findsOneWidget,
      );
      expect(
        inKey('leave-history-1', find.text('Plans changed.')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('leave-cancel')), findsNothing);

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(
        inKey('leave-row-01J0LEAVE0000000000000AAAA', find.text('Cancelled')),
        findsOneWidget,
      );
    });

    testWidgets('dismissing the dialog sends nothing', (tester) async {
      await pumpApp(tester);
      await openLeave(tester);
      await tapKey(tester, 'leave-row-01J0LEAVE0000000000000AAAA');
      await tapKey(tester, 'leave-cancel');
      await tester.tap(find.text('Keep it'));
      await tester.pumpAndSettle();

      expect(posts(), isEmpty);
    });

    testWidgets('approved leave: Cancel only before its first day', (
      tester,
    ) async {
      server.mine = [
        leaveJson(
          publicId: 'FUTURE',
          status: 'approved',
          start: '2026-10-15',
          end: '2026-10-15',
        ),
        leaveJson(
          publicId: 'STARTED',
          status: 'approved',
          start: '2026-10-14',
          end: '2026-10-16',
        ),
      ];
      await pumpApp(tester);
      await openLeave(tester);

      await tapKey(tester, 'leave-row-FUTURE');
      expect(find.byKey(const Key('leave-cancel')), findsOneWidget);
      await tester.pageBack();
      await tester.pumpAndSettle();

      await tapKey(tester, 'leave-row-STARTED');
      expect(find.byKey(const Key('leave-cancel')), findsNothing);
    });
  });

  group('Team requests (R-3, R-7)', () {
    testWidgets('a manager sees the pending count and their reports', (
      tester,
    ) async {
      makeManager();
      await pumpApp(tester);
      await openLeave(tester);

      expect(inKey('leave-team', find.text('2 pending')), findsOneWidget);
      await tapKey(tester, 'leave-team');

      expect(find.byType(TeamLeavePage), findsOneWidget);
      expect(find.text('Pending (2)'), findsOneWidget);
      // Server order: soonest first.
      expect(
        tester.getTopLeft(find.byKey(const Key('team-leave-row-T-SOON'))).dy,
        lessThan(
          tester.getTopLeft(find.byKey(const Key('team-leave-row-T-LATER'))).dy,
        ),
      );
      expect(
        inKey('team-leave-row-T-SOON', find.text('Alan Turing')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('team-leave-row-T-DONE')), findsNothing);

      await tapKey(tester, 'team-leave-filter-approved');
      expect(find.byKey(const Key('team-leave-row-T-DONE')), findsOneWidget);
      expect(find.byKey(const Key('team-leave-row-T-SOON')), findsNothing);
    });

    testWidgets('approve with a note; the list and Schedule reload', (
      tester,
    ) async {
      makeManager();
      await pumpApp(tester);
      // Visit Schedule first so its page exists.
      await tester.tap(find.widgetWithText(NavigationDestination, 'Schedule'));
      await tester.pumpAndSettle();
      final scheduleReads = requests
          .where((r) => r.url.path.endsWith('/me/schedule'))
          .length;

      await openLeave(tester);
      await tapKey(tester, 'leave-team');
      await tapKey(tester, 'team-leave-row-T-SOON');

      expect(find.text('Team request'), findsOneWidget);
      expect(find.text('Alan Turing'), findsOneWidget);
      expect(find.text('Appointment.'), findsOneWidget);

      await tapKey(tester, 'leave-approve');
      await tester.enterText(
        find.byKey(const Key('leave-dialog-text')),
        'Enjoy.',
      );
      await tapKey(tester, 'leave-approve-confirm');

      expect(
        posts().single.url.path,
        endsWith('/leave-requests/T-SOON/approve'),
      );
      expect(server.bodyOf(posts().single), {'note': 'Enjoy.'});
      expect(find.text('Request approved.'), findsOneWidget);
      expect(find.byKey(const Key('leave-status-approved')), findsOneWidget);
      expect(find.byKey(const Key('leave-approve')), findsNothing);
      expect(
        requests.where((r) => r.url.path.endsWith('/me/schedule')).length,
        greaterThan(scheduleReads),
      );

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('Pending (1)'), findsOneWidget);
      expect(find.byKey(const Key('team-leave-row-T-SOON')), findsNothing);
    });

    testWidgets('reject needs a reason', (tester) async {
      makeManager();
      await pumpApp(tester);
      await openLeave(tester);
      await tapKey(tester, 'leave-team');
      await tapKey(tester, 'team-leave-row-T-LATER');

      await tapKey(tester, 'leave-reject');
      await tapKey(tester, 'leave-reject-confirm');
      expect(
        find.text('Give a reason (at least 3 characters).'),
        findsOneWidget,
      );
      expect(posts(), isEmpty);

      await tester.enterText(
        find.byKey(const Key('leave-dialog-text')),
        'Release week.',
      );
      await tapKey(tester, 'leave-reject-confirm');

      expect(server.bodyOf(posts().single), {'reason': 'Release week.'});
      expect(find.byKey(const Key('leave-status-rejected')), findsOneWidget);
    });

    testWidgets('decided elsewhere: the message, and the buttons go', (
      tester,
    ) async {
      makeManager();
      server.override = (r) {
        if (r.method == 'POST') {
          server.team[1]['status'] = 'rejected';

          return jsonError(
            409,
            'Only a pending leave request can be approved.',
          );
        }

        return null;
      };
      await pumpApp(tester);
      await openLeave(tester);
      await tapKey(tester, 'leave-team');
      await tapKey(tester, 'team-leave-row-T-SOON');
      await tapKey(tester, 'leave-approve');
      await tapKey(tester, 'leave-approve-confirm');

      expect(
        inKey(
          'leave-action-error',
          find.text('Only a pending leave request can be approved.'),
        ),
        findsOneWidget,
      );
      expect(find.byKey(const Key('leave-status-rejected')), findsOneWidget);
      expect(find.byKey(const Key('leave-approve')), findsNothing);
    });

    testWidgets('a reviewer who manages nobody has no Team requests', (
      tester,
    ) async {
      server
        ..canReview = true
        ..directReports = 0;
      await pumpApp(tester);
      await openLeave(tester);

      expect(find.byKey(const Key('leave-team')), findsNothing);
    });
  });

  group('appearance', () {
    for (final (label, brightness) in [
      ('light', Brightness.light),
      ('dark', Brightness.dark),
    ]) {
      testWidgets(
        'Leave, the form, a request and a team request fit a small phone '
        'in $label mode at 200% text',
        (tester) async {
          tester.platformDispatcher.platformBrightnessTestValue = brightness;
          tester.platformDispatcher.textScaleFactorTestValue = 2.0;
          addTearDown(tester.platformDispatcher.clearAllTestValues);
          tester.view.physicalSize =
              const Size(320, 568) * tester.view.devicePixelRatio;
          addTearDown(tester.view.resetPhysicalSize);
          makeManager();
          server.mine = [leaveJson(reason: 'A long reason ' * 12)];

          await pumpApp(tester, tall: false);
          await openLeave(tester);
          expect(tester.takeException(), isNull);
          expect(
            Theme.of(tester.element(find.byType(LeavePage))).brightness,
            brightness,
          );

          await tapKey(tester, 'leave-request');
          expect(tester.takeException(), isNull);
          await tester.pageBack();
          await tester.pumpAndSettle();

          await tester.scrollUntilVisible(
            find.byKey(const Key('leave-row-01J0LEAVE0000000000000AAAA')),
            200,
            scrollable: find
                .descendant(
                  of: find.byType(LeavePage),
                  matching: find.byType(Scrollable),
                )
                .first,
          );
          await tapKey(tester, 'leave-row-01J0LEAVE0000000000000AAAA');
          expect(tester.takeException(), isNull);
          await tester.pageBack();
          await tester.pumpAndSettle();

          await tester.scrollUntilVisible(
            find.byKey(const Key('leave-team')),
            -200,
            scrollable: find
                .descendant(
                  of: find.byType(LeavePage),
                  matching: find.byType(Scrollable),
                )
                .first,
          );
          await tapKey(tester, 'leave-team');
          await tapKey(tester, 'team-leave-row-T-SOON');
          expect(tester.takeException(), isNull);
          await tester.scrollUntilVisible(
            find.byKey(const Key('leave-reject')),
            200,
            scrollable: find
                .descendant(
                  of: find.byType(LeaveRequestPage),
                  matching: find.byType(Scrollable),
                )
                .first,
          );
          await tapKey(tester, 'leave-reject');
          expect(tester.takeException(), isNull);
        },
      );
    }

    testWidgets('controls meet tap-target and label guidelines', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      makeManager();
      await pumpApp(tester);
      await openLeave(tester);

      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));

      await tapKey(tester, 'leave-request');
      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      semantics.dispose();
    });
  });
}

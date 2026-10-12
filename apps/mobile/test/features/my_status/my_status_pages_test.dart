import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/my_status/location/location_service.dart';
import 'package:mobile/features/my_status/presentation/check_in_page.dart';
import 'package:mobile/features/my_status/presentation/history_pages.dart';
import 'package:mobile/features/my_status/presentation/my_status_page.dart';
import 'package:mobile/features/people/presentation/more_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart' show homeClientFor, homeResponse;
import '../../support/my_status_fixtures.dart';
import '../../support/people_fixtures.dart' show jsonResponse;

/// Phase 30B Gate 3 — More → My status, the two "All" lists and the
/// check-in flow through the real app (`CompanyApp`, router, shell,
/// `ApiClient`), against an in-memory status and check-in API and a fake
/// device (docs/phases/V1_PHASE_30_DEFINITION.md §6.5, R-13…R-22). The
/// company day is Wed 14 Oct in Manila.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late FakeLocationService device;
  late List<http.Request> requests;

  /// Newest first, as the server keeps them.
  late List<Map<String, dynamic>> statuses;
  late List<Map<String, dynamic>> checkIns;

  /// When set, answers every `/me/status` and `/me/check-ins` call.
  Future<http.Response> Function(http.Request r)? override;

  /// The in-memory status and check-in server. Overrides delegate to it.
  Future<http.Response> serve(http.Request r) async {
    final path = r.url.path;

    final isStatus = path.endsWith('/me/status');
    if (!isStatus && !path.endsWith('/me/check-ins')) {
      fail('unexpected request: ${r.url}');
    }

    if (r.method == 'POST') {
      final body = jsonDecode(r.body) as Map<String, dynamic>;
      if (isStatus) {
        final entry = statusJson(
          body['status'] as String,
          recordedAt: '2026-10-14T04:00:00+00:00',
        );
        statuses.insert(0, entry);

        return created(entry);
      }
      final entry = checkInJson(
        publicId: 'NEW${checkIns.length}',
        latitude: body['latitude'] as Object,
        longitude: body['longitude'] as Object,
        accuracy: body['accuracy_meters'],
        label: body['location_label'],
        note: body['note'],
        checkedInAt: '2026-10-14T04:05:00+00:00',
      );
      checkIns.insert(0, entry);

      return created(entry);
    }

    final rows = isStatus ? statuses : checkIns;
    final perPage = int.parse(r.url.queryParameters['per_page'] ?? '20');
    final page = int.parse(r.url.queryParameters['page'] ?? '1');

    return jsonResponse(
      historyBody(
        rows.skip((page - 1) * perPage).take(perPage).toList(),
        page: page,
        lastPage: rows.isEmpty ? 1 : (rows.length / perPage).ceil(),
        total: rows.length,
        perPage: perPage,
      ),
    );
  }

  /// Records every request once, then lets [override] answer it, or
  /// [serve].
  Future<http.Response> route(http.Request r) async {
    requests.add(r);
    if (r.url.path.endsWith('/me/home')) {
      return homeResponse();
    }

    return (override ?? serve)(r);
  }

  setUp(() {
    requests = [];
    override = null;
    device = FakeLocationService()..reads = [fixAt(DateTime.now())];
    statuses = [
      statusJson('busy', recordedAt: '2026-10-14T01:00:00+00:00'),
      statusJson('available', recordedAt: '2026-10-13T00:30:00+00:00'),
    ];
    checkIns = [
      checkInJson(),
      checkInJson(
        publicId: 'OLD',
        label: null,
        note: null,
        accuracy: null,
        checkedInAt: '2026-10-10T08:00:00+00:00',
      ),
    ];
  });

  // A gesture that misses its target must fail the test.
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
        locationService: device,
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

  Future<void> openMyStatus(WidgetTester tester) async {
    await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
    await tester.pumpAndSettle();
    await tapKey(tester, 'more-status');
  }

  Finder inKey(String key, Finder matching) =>
      find.descendant(of: find.byKey(Key(key)), matching: matching);

  List<http.Request> posts(String path) => [
    for (final r in requests)
      if (r.method == 'POST' && r.url.path.endsWith(path)) r,
  ];

  group('More and My status (R-13)', () {
    testWidgets('More shows my current status; My status shows it all', (
      tester,
    ) async {
      await pumpApp(tester);
      await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
      await tester.pumpAndSettle();
      expect(inKey('more-status', find.text('Busy')), findsOneWidget);

      await tapKey(tester, 'more-status');
      expect(find.byType(MyStatusPage), findsOneWidget);

      // 01:00 UTC is 09:00 in Manila, on the company today.
      expect(inKey('my-status-current', find.text('Busy')), findsOneWidget);
      expect(
        inKey('my-status-current', find.text('Set Today 09:00')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('my-status-hint')), findsNothing);

      final recent = find.byKey(
        const Key('my-status-check-in-01J0CHECKIN000000000000AAA'),
      );
      for (final text in [
        'Client site',
        'Today 10:30 · ±12 m',
        'Gate B',
        '14.55470, 121.02440',
      ]) {
        expect(
          find.descendant(of: recent, matching: find.text(text)),
          findsOneWidget,
          reason: text,
        );
      }
      final old = find.byKey(const Key('my-status-check-in-OLD'));
      expect(
        find.descendant(of: old, matching: find.text('No place name')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: old, matching: find.text('Sat 10 Oct 16:00')),
        findsOneWidget,
      );
      // Yesterday in Manila (08:30 on 13 Oct).
      expect(find.text('Yesterday 08:30'), findsOneWidget);
      expect(find.byKey(const Key('my-status-all-check-ins')), findsOneWidget);
      expect(find.byKey(const Key('my-status-all-history')), findsOneWidget);
      // Read-only: no map and no way to edit a check-in.
      expect(find.byIcon(Icons.map_outlined), findsNothing);
    });

    testWidgets('a person with no status or check-ins yet', (tester) async {
      statuses = [];
      checkIns = [];
      await pumpApp(tester);
      await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
      await tester.pumpAndSettle();
      expect(inKey('more-status', find.text('No status set')), findsOneWidget);

      await tapKey(tester, 'more-status');
      expect(find.text('No status set yet'), findsOneWidget);
      expect(find.byKey(const Key('my-status-no-check-ins')), findsOneWidget);
      expect(find.byKey(const Key('my-status-no-history')), findsOneWidget);
      expect(find.byKey(const Key('my-status-all-check-ins')), findsNothing);
      expect(find.byKey(const Key('my-status-all-history')), findsNothing);
      expect(find.byKey(const Key('my-status-check-in')), findsOneWidget);
    });

    testWidgets('Off duty says it isn\'t leave (R-16)', (tester) async {
      statuses = [statusJson('off_duty')];
      await pumpApp(tester);
      await openMyStatus(tester);

      expect(
        inKey(
          'my-status-current',
          find.text("Not working right now. This isn't leave."),
        ),
        findsOneWidget,
      );
    });

    testWidgets('no profile: only the message, no Check in (R-15)', (
      tester,
    ) async {
      override = (r) async => jsonError(403, 'No staff record is linked.');
      await pumpApp(tester);
      await tester.tap(find.widgetWithText(NavigationDestination, 'More'));
      await tester.pumpAndSettle();
      expect(
        inKey('more-status', find.text('Your status and check-ins')),
        findsOneWidget,
      );

      await tapKey(tester, 'more-status');
      expect(find.byKey(const Key('my-status-no-profile')), findsOneWidget);
      expect(find.byKey(const Key('my-status-check-in')), findsNothing);
      expect(find.byKey(const Key('my-status-change')), findsNothing);
      expect(auth.status, AuthStatus.authenticated);
    });

    testWidgets('a failed load shows the error; Try again recovers', (
      tester,
    ) async {
      override = (r) async => throw const NetworkFailure();
      await pumpApp(tester);
      await openMyStatus(tester);
      expect(
        find.text("Couldn't load your status. Check your connection."),
        findsOneWidget,
      );

      override = null;
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();
      expect(inKey('my-status-current', find.text('Busy')), findsOneWidget);
    });

    testWidgets('returning to More refreshes its subtitle', (tester) async {
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-change');
      await tapKey(tester, 'status-choice-in_meeting');

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byType(MorePage), findsOneWidget);
      expect(inKey('more-status', find.text('In a meeting')), findsOneWidget);
    });
  });

  group('Change status (R-16)', () {
    testWidgets('the sheet lists the five, marks mine, and sends only on '
        'choice; the screen changes only once the server confirms', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMyStatus(tester);

      await tapKey(tester, 'my-status-change');
      for (final (wire, label) in [
        ('available', 'Available'),
        ('busy', 'Busy'),
        ('in_meeting', 'In a meeting'),
        ('in_field', 'In the field'),
        ('off_duty', 'Off duty'),
      ]) {
        expect(
          inKey('status-choice-$wire', find.text(label)),
          findsOneWidget,
          reason: wire,
        );
      }
      expect(inKey('status-choice-busy', find.byIcon(Icons.check)), findsOne);
      expect(
        inKey(
          'status-choice-off_duty',
          find.text("Not working right now. This isn't leave."),
        ),
        findsOneWidget,
      );
      expect(posts('/me/status'), isEmpty);

      // Hold the server's answer: the screen must not change yet.
      final reply = Completer<void>();
      override = (r) async {
        if (r.method == 'POST') {
          await reply.future;
          override = null;
        }

        return serve(r);
      };
      await tester.tap(find.byKey(const Key('status-choice-in_field')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(inKey('my-status-current', find.text('Busy')), findsOneWidget);
      expect(find.byType(LinearProgressIndicator), findsOneWidget);
      expect(
        tester
            .widget<ButtonStyleButton>(
              find.byKey(const Key('my-status-check-in')),
            )
            .onPressed,
        isNull,
      );

      reply.complete();
      await tester.pumpAndSettle();
      expect(
        inKey('my-status-current', find.text('In the field')),
        findsOneWidget,
      );
      expect(find.text('Status set to In the field.'), findsOneWidget);
      expect(jsonDecode(posts('/me/status').single.body), {
        'status': 'in_field',
      });
    });

    testWidgets('closing the sheet sends nothing', (tester) async {
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-change');

      await tester.tapAt(const Offset(10, 10));
      await tester.pumpAndSettle();

      expect(posts('/me/status'), isEmpty);
      expect(inKey('my-status-current', find.text('Busy')), findsOneWidget);
    });

    testWidgets('a failed change keeps the old status and says why', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMyStatus(tester);
      override = (r) async =>
          r.method == 'POST' ? throw const NetworkFailure() : serve(r);

      await tapKey(tester, 'my-status-change');
      await tapKey(tester, 'status-choice-off_duty');

      expect(inKey('my-status-current', find.text('Busy')), findsOneWidget);
      expect(
        find.text("Couldn't change your status. Check your connection."),
        findsOneWidget,
      );
    });
  });

  group('Check in (R-17…R-21)', () {
    testWidgets('already allowed: one position, the form, Send, then the new '
        'check-in on top', (tester) async {
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');

      expect(find.byType(CheckInPage), findsOneWidget);
      expect(device.requests, 0);
      expect(device.positionReads, 1);
      expect(find.text('Location found (±9 m)'), findsOneWidget);
      expect(find.text('14.55471, 121.02445'), findsOneWidget);
      expect(posts('/me/check-ins'), isEmpty);

      await tester.enterText(
        find.byKey(const Key('check-in-place')),
        'Warehouse 3',
      );
      await tester.enterText(
        find.byKey(const Key('check-in-note')),
        'Delivery check',
      );
      await tapKey(tester, 'check-in-send');

      expect(jsonDecode(posts('/me/check-ins').single.body), {
        'latitude': 14.5547123,
        'longitude': 121.0244456,
        'accuracy_meters': 9,
        'location_label': 'Warehouse 3',
        'note': 'Delivery check',
      });
      expect(find.byType(CheckInPage), findsNothing);
      expect(find.byType(MyStatusPage), findsOneWidget);
      expect(find.text('Checked in.'), findsOneWidget);
      final first = find.byKey(const Key('my-status-check-in-NEW2'));
      expect(
        find.descendant(of: first, matching: find.text('Warehouse 3')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: first, matching: find.text('Today 12:05 · ±9 m')),
        findsOneWidget,
      );
      expect(
        tester.getTopLeft(first).dy,
        lessThan(
          tester
              .getTopLeft(
                find.byKey(
                  const Key('my-status-check-in-01J0CHECKIN000000000000AAA'),
                ),
              )
              .dy,
        ),
      );
    });

    testWidgets('approximate location is labelled as such', (tester) async {
      device.reads = [fixAt(DateTime.now(), approximate: true, accuracy: 1800)];
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');

      expect(find.text('Approximate location (±1800 m)'), findsOneWidget);
      await tapKey(tester, 'check-in-send');
      expect(
        (jsonDecode(posts('/me/check-ins').single.body)
            as Map)['accuracy_meters'],
        1800,
      );
    });

    testWidgets('not yet allowed: the explanation first; Not now sends '
        'nothing and never asks Android', (tester) async {
      device.current = LocationPermissionState.denied;
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');

      expect(
        tester.widget<Text>(find.byKey(const Key('check-in-explanation'))).data,
        'Company App reads your location once, only when you tap Check in. '
        'It never tracks you in the background. Your manager and '
        "administrators can see your check-ins. A check-in isn't a clock-in.",
      );
      expect(device.requests, 0);
      expect(device.positionReads, 0);

      await tapKey(tester, 'check-in-cancel');
      expect(find.byType(MyStatusPage), findsOneWidget);
      expect(device.requests, 0);
      expect(posts('/me/check-ins'), isEmpty);
      expect(find.text('Checked in.'), findsNothing);
    });

    testWidgets('Continue asks Android, then reads the position', (
      tester,
    ) async {
      device.current = LocationPermissionState.denied;
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');
      await tapKey(tester, 'check-in-continue');

      expect(device.requests, 1);
      expect(find.byKey(const Key('check-in-send')), findsOneWidget);
    });

    testWidgets('denied, then denied for good: messages and app settings', (
      tester,
    ) async {
      device
        ..current = LocationPermissionState.denied
        ..onRequest = LocationPermissionState.denied;
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');
      await tapKey(tester, 'check-in-continue');

      expect(
        find.text('Location permission is needed to check in.'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('check-in-open-app-settings')), findsNothing);

      device.onRequest = LocationPermissionState.deniedForever;
      await tapKey(tester, 'check-in-retry');
      await tapKey(tester, 'check-in-continue');
      await tapKey(tester, 'check-in-open-app-settings');
      expect(device.appSettingsOpened, 1);
      expect(device.positionReads, 0);
      expect(posts('/me/check-ins'), isEmpty);
    });

    testWidgets('location off: the message, location settings, then Try '
        'again', (tester) async {
      device.enabled = false;
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');

      expect(find.text('Turn on location to check in.'), findsOneWidget);
      await tapKey(tester, 'check-in-open-location-settings');
      expect(device.locationSettingsOpened, 1);

      device.enabled = true;
      await tapKey(tester, 'check-in-retry');
      expect(find.byKey(const Key('check-in-send')), findsOneWidget);
    });

    testWidgets('no position in time: the message and nothing sent', (
      tester,
    ) async {
      device.reads = [const LocationTimeoutException()];
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');

      expect(
        find.text(
          "Couldn't get your location. Try again, ideally outdoors or near a "
          'window.',
        ),
        findsOneWidget,
      );
      expect(find.byKey(const Key('check-in-send')), findsNothing);
      expect(posts('/me/check-ins'), isEmpty);
    });

    testWidgets('offline at Send: the form stays; sending again works once', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');
      await tester.enterText(find.byKey(const Key('check-in-place')), 'Yard');
      override = (r) async =>
          r.method == 'POST' ? throw const NetworkFailure() : serve(r);

      await tapKey(tester, 'check-in-send');
      expect(find.byType(CheckInPage), findsOneWidget);
      expect(
        find.text(
          "Couldn't send your check-in. Check your connection and try again.",
        ),
        findsOneWidget,
      );
      expect(find.text('Yard'), findsOneWidget);

      override = null;
      await tapKey(tester, 'check-in-send');
      expect(find.byType(MyStatusPage), findsOneWidget);
      expect(
        checkIns.where((c) => c['location_label'] == 'Yard'),
        hasLength(1),
      );
    });

    testWidgets('Back is held while sending, then the check-in returns', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');
      final reply = Completer<void>();
      override = (r) async {
        if (r.method == 'POST') {
          await reply.future;
        }

        return serve(r);
      };

      await tester.tap(find.byKey(const Key('check-in-send')));
      await tester.pump();
      expect(
        tester
            .widget<PopScope>(
              find
                  .ancestor(
                    of: find.byKey(const Key('check-in-send')),
                    matching: find.byWidgetPredicate((w) => w is PopScope),
                  )
                  .first,
            )
            .canPop,
        isFalse,
      );
      await tester.binding.handlePopRoute();
      await tester.pump();
      expect(find.byType(CheckInPage), findsOneWidget);

      reply.complete();
      await tester.pumpAndSettle();
      expect(find.byType(MyStatusPage), findsOneWidget);
      expect(find.text('Checked in.'), findsOneWidget);
    });

    testWidgets('Cancel and Back send nothing', (tester) async {
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-check-in');
      await tapKey(tester, 'check-in-cancel');
      expect(find.byType(MyStatusPage), findsOneWidget);

      await tapKey(tester, 'my-status-check-in');
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byType(MyStatusPage), findsOneWidget);
      expect(posts('/me/check-ins'), isEmpty);
      expect(find.text('Checked in.'), findsNothing);
    });
  });

  group('All lists', () {
    testWidgets('All check-ins pages 20 at a time without repeats', (
      tester,
    ) async {
      checkIns = [
        for (var i = 0; i < 23; i++)
          checkInJson(publicId: 'C${i.toString().padLeft(2, '0')}'),
      ];
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-all-check-ins');

      expect(find.byType(CheckInsPage), findsOneWidget);
      expect(find.byKey(const Key('check-ins-search')), findsNothing);
      await tester.scrollUntilVisible(
        find.byKey(const Key('check-ins-row-C22')),
        300,
        scrollable: find.descendant(
          of: find.byKey(const Key('check-ins-list')),
          matching: find.byType(Scrollable),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('check-ins-row-C22')), findsOneWidget);
      final pages = [
        for (final r in requests)
          if (r.url.path.endsWith('/me/check-ins') &&
              r.url.queryParameters['per_page'] == '20')
            r.url.queryParameters['page'],
      ];
      expect(pages, ['1', '2']);
    });

    testWidgets('All status changes lists them with their times', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMyStatus(tester);
      await tapKey(tester, 'my-status-all-history');

      expect(find.byType(StatusHistoryPage), findsOneWidget);
      expect(find.text('Today 09:00'), findsOneWidget);
      expect(find.text('Yesterday 08:30'), findsOneWidget);
      expect(find.text('Available'), findsOneWidget);
    });
  });

  group('appearance', () {
    for (final (label, brightness) in [
      ('light', Brightness.light),
      ('dark', Brightness.dark),
    ]) {
      testWidgets(
        'My status, the sheet, the explanation and the form fit a small '
        'phone in $label mode at 200% text',
        (tester) async {
          tester.platformDispatcher.platformBrightnessTestValue = brightness;
          tester.platformDispatcher.textScaleFactorTestValue = 2.0;
          addTearDown(tester.platformDispatcher.clearAllTestValues);
          tester.view.physicalSize =
              const Size(320, 568) * tester.view.devicePixelRatio;
          addTearDown(tester.view.resetPhysicalSize);
          checkIns.insert(
            0,
            checkInJson(
              publicId: 'LONG',
              label: 'A very long place name that goes on well past one line',
              note: 'A long note ' * 8,
            ),
          );
          device.current = LocationPermissionState.denied;

          await pumpApp(tester, tall: false);
          await openMyStatus(tester);
          expect(tester.takeException(), isNull);
          expect(
            Theme.of(tester.element(find.byType(MyStatusPage))).brightness,
            brightness,
          );

          await tapKey(tester, 'my-status-change');
          expect(tester.takeException(), isNull);
          // At 200% the sheet fills a small screen; close it as Back would.
          Navigator.of(
            tester.element(find.byKey(const Key('status-choice-busy'))),
          ).pop();
          await tester.pumpAndSettle();

          await tapKey(tester, 'my-status-check-in');
          expect(find.byKey(const Key('check-in-explanation')), findsOneWidget);
          expect(tester.takeException(), isNull);
          // The list builds lazily: scroll to what's below the fold.
          Finder flowScrollable() => find
              .descendant(
                of: find.byType(CheckInPage),
                matching: find.byType(Scrollable),
              )
              .first;
          await tester.scrollUntilVisible(
            find.byKey(const Key('check-in-continue')),
            200,
            scrollable: flowScrollable(),
          );
          await tapKey(tester, 'check-in-continue');
          expect(find.byKey(const Key('check-in-place')), findsOneWidget);
          await tester.scrollUntilVisible(
            find.byKey(const Key('check-in-send')),
            200,
            scrollable: flowScrollable(),
          );
          await tester.pumpAndSettle();
          expect(
            find.byKey(const Key('check-in-send')).hitTestable(),
            findsOneWidget,
          );
          expect(tester.takeException(), isNull);
        },
      );
    }

    testWidgets('controls meet tap-target and label guidelines', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      await pumpApp(tester);
      await openMyStatus(tester);

      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      semantics.dispose();
    });
  });
}

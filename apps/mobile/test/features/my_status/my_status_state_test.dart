import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';
import 'package:mobile/features/my_status/location/location_service.dart';
import 'package:mobile/features/my_status/state/check_in_flow_controller.dart';
import 'package:mobile/features/my_status/state/history_list_controllers.dart';
import 'package:mobile/features/my_status/state/my_status_controller.dart';

import '../../support/fake_backend.dart';
import '../../support/my_status_fixtures.dart';
import '../../support/people_fixtures.dart' show jsonResponse;

/// Phase 30B Gate 2 — [MyStatusApiClient], the My status controllers and
/// the check-in flow over the real `ApiClient` and a fake device (spec
/// §6.4, R-13…R-20): exact requests, confirmed status changes, no profile,
/// errors, paging, and a check-in that is explicit, one position, one
/// `POST`, and never sent without a position.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late List<http.Request> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
  });

  void answer(Future<http.Response> Function(http.Request r) respond) {
    backend.onApi = (request) {
      requests.add(request);

      return respond(request);
    };
  }

  List<http.Request> posts(String path) => [
    for (final r in requests)
      if (r.method == 'POST' && r.url.path.endsWith(path)) r,
  ];

  /// `/me/status` and `/me/check-ins` with the given rows (newest first).
  Future<http.Response> Function(http.Request) server({
    List<Map<String, dynamic>>? statuses,
    List<Map<String, dynamic>>? checkIns,
  }) => (r) async {
    final rows = r.url.path.endsWith('/me/status')
        ? statuses ?? [statusJson('busy'), statusJson('available')]
        : checkIns ?? [checkInJson()];
    final perPage = int.parse(r.url.queryParameters['per_page'] ?? '20');
    final page = int.parse(r.url.queryParameters['page'] ?? '1');
    final last = rows.isEmpty ? 1 : (rows.length / perPage).ceil();

    return jsonResponse(
      historyBody(
        rows.skip((page - 1) * perPage).take(perPage).toList(),
        page: page,
        lastPage: last,
        total: rows.length,
        perPage: perPage,
      ),
    );
  };

  group('MyStatusApiClient', () {
    test('GETs both histories with explicit paging', () async {
      answer(server());
      final client = myStatusClientFor(backend, auth);

      await client.fetchStatusHistory(page: 2);
      await client.fetchCheckIns(perPage: 5);

      expect(requests[0].url.path, '/api/v1/me/status');
      expect(requests[0].url.queryParameters, {'per_page': '20', 'page': '2'});
      expect(requests[1].url.path, '/api/v1/me/check-ins');
      expect(requests[1].url.queryParameters, {'per_page': '5', 'page': '1'});
    });

    test('POSTs exactly the status, and exactly the check-in fields', () async {
      answer(
        (r) async => r.url.path.endsWith('/me/status')
            ? created(statusJson('in_field'))
            : created(checkInJson()),
      );
      final client = myStatusClientFor(backend, auth);

      final entry = await client.setStatus(OperationalStatus.inField);
      expect(entry.status, OperationalStatus.inField);
      expect(jsonDecode(posts('/me/status').single.body), {
        'status': 'in_field',
      });

      await client.checkIn(
        CheckInDraft(
          fix: fixAt(DateTime(2026)),
          locationLabel: 'Site',
          note: '',
        ),
      );
      expect(jsonDecode(posts('/me/check-ins').single.body), {
        'latitude': 14.5547123,
        'longitude': 121.0244456,
        'accuracy_meters': 9,
        'location_label': 'Site',
        'note': null,
      });
    });

    test('never sends the unknown status', () async {
      answer(server());
      expect(
        () => myStatusClientFor(
          backend,
          auth,
        ).setStatus(OperationalStatus.unknown),
        throwsArgumentError,
      );
      expect(requests, isEmpty);
    });

    test(
      'a bad shape is an ApiRequestException; a 403 keeps the session',
      () async {
        answer((r) async => jsonResponse({'data': []}));
        await expectLater(
          myStatusClientFor(backend, auth).fetchStatusHistory(),
          throwsA(isA<ApiRequestException>()),
        );

        answer(
          (r) async =>
              jsonError(403, 'No staff record is linked to this account.'),
        );
        await expectLater(
          myStatusClientFor(backend, auth).fetchCheckIns(),
          throwsA(isA<ApiForbiddenException>()),
        );
        expect(auth.status, AuthStatus.authenticated);
      },
    );
  });

  group('MyStatusController', () {
    test(
      'loads my current status and the 5 newest of each, with the company day',
      () async {
        answer(server());
        final c = MyStatusController(myStatusClientFor(backend, auth));
        addTearDown(c.dispose);

        await c.load();

        expect(c.state, MyStatusState.loaded);
        expect(c.current!.status, OperationalStatus.busy);
        expect(c.recentStatuses, hasLength(2));
        expect(c.recentCheckIns.single.locationLabel, 'Client site');
        expect(c.companyDay!.date, '2026-10-14');
        expect(requests.map((r) => r.url.queryParameters['per_page']).toSet(), {
          '5',
        });
      },
    );

    test('a person who never set a status has no current status', () async {
      answer(server(statuses: [], checkIns: []));
      final c = MyStatusController(myStatusClientFor(backend, auth));
      addTearDown(c.dispose);

      await c.load();

      expect(c.state, MyStatusState.loaded);
      expect(c.current, isNull);
      expect(c.recentCheckIns, isEmpty);
    });

    test('a 403 is the no-profile state, the session kept (R-15)', () async {
      answer(
        (r) async =>
            jsonError(403, 'No staff record is linked to this account.'),
      );
      final c = MyStatusController(myStatusClientFor(backend, auth));
      addTearDown(c.dispose);

      await c.load();

      expect(c.state, MyStatusState.noProfile);
      expect(auth.status, AuthStatus.authenticated);
    });

    test('a failed load is an error with a message; a failed refresh keeps the content', () async {
      answer((r) async => throw const NetworkFailure());
      final c = MyStatusController(myStatusClientFor(backend, auth));
      addTearDown(c.dispose);

      await c.load();
      expect(c.state, MyStatusState.error);
      expect(
        c.errorMessage,
        "Couldn't load your status. Check your connection.",
      );

      answer(server());
      await c.load();
      answer((r) async => throw const NetworkFailure());
      expect(await c.refresh(), isFalse);
      expect(c.state, MyStatusState.loaded);
      expect(c.current!.status, OperationalStatus.busy);
    });

    test(
      'a status change shows only once the server confirms it (R-16)',
      () async {
        answer(server());
        final c = MyStatusController(myStatusClientFor(backend, auth));
        addTearDown(c.dispose);
        await c.load();

        final reply = Completer<http.Response>();
        answer((r) => reply.future);
        final saving = c.setStatus(OperationalStatus.offDuty);
        await Future<void>.delayed(Duration.zero);

        expect(c.saving, OperationalStatus.offDuty);
        expect(
          c.current!.status,
          OperationalStatus.busy,
          reason: 'not optimistic',
        );
        expect(
          await c.setStatus(OperationalStatus.busy),
          isFalse,
          reason: 'one at a time',
        );

        reply.complete(
          created(
            statusJson('off_duty', recordedAt: '2026-10-14T03:00:00+00:00'),
          ),
        );
        expect(await saving, isTrue);
        expect(c.saving, isNull);
        expect(c.current!.status, OperationalStatus.offDuty);
        expect(c.recentStatuses.map((e) => e.status), [
          OperationalStatus.offDuty,
          OperationalStatus.busy,
          OperationalStatus.available,
        ]);
        expect(posts('/me/status'), hasLength(1));
      },
    );

    test('the recent list keeps 5 after a change', () async {
      answer(
        server(statuses: [for (var i = 0; i < 5; i++) statusJson('busy')]),
      );
      final c = MyStatusController(myStatusClientFor(backend, auth));
      addTearDown(c.dispose);
      await c.load();

      answer((r) async => created(statusJson('available')));
      await c.setStatus(OperationalStatus.available);

      expect(c.recentStatuses, hasLength(5));
      expect(c.recentStatuses.first.status, OperationalStatus.available);
    });

    test('a failed change keeps the old status and says why', () async {
      answer(server());
      final c = MyStatusController(myStatusClientFor(backend, auth));
      addTearDown(c.dispose);
      await c.load();

      answer((r) async => throw const NetworkFailure());
      expect(await c.setStatus(OperationalStatus.inField), isFalse);
      expect(c.current!.status, OperationalStatus.busy);
      expect(
        c.saveError,
        "Couldn't change your status. Check your connection.",
      );

      answer(
        (r) async => http.Response(
          jsonEncode({
            'message': 'The selected status is invalid.',
            'errors': {
              'status': ['The selected status is invalid.'],
            },
          }),
          422,
        ),
      );
      expect(await c.setStatus(OperationalStatus.inField), isFalse);
      expect(c.saveError, 'The selected status is invalid.');

      answer((r) async => created(statusJson('in_field')));
      expect(await c.setStatus(OperationalStatus.inField), isTrue);
      expect(c.saveError, isNull);
    });

    test('a refresh overtaken by a confirmed change is dropped', () async {
      answer(server());
      final c = MyStatusController(myStatusClientFor(backend, auth));
      addTearDown(c.dispose);
      await c.load();

      // The refresh's two GETs wait; each gets a valid body for its path.
      final slow = Completer<void>();
      answer((r) async {
        if (r.method == 'POST') {
          return created(statusJson('in_meeting'));
        }
        await slow.future;

        return jsonResponse(
          historyBody(
            r.url.path.endsWith('/me/status')
                ? [statusJson('available')]
                : [checkInJson()],
          ),
        );
      });
      final refreshing = c.refresh();
      await c.setStatus(OperationalStatus.inMeeting);
      slow.complete();
      await refreshing;

      expect(c.current!.status, OperationalStatus.inMeeting);
    });

    test('a confirmed check-in goes on top of the recent list', () async {
      answer(
        server(
          checkIns: [for (var i = 0; i < 5; i++) checkInJson(publicId: 'C$i')],
        ),
      );
      final c = MyStatusController(myStatusClientFor(backend, auth));
      addTearDown(c.dispose);
      await c.load();

      c.addCheckIn(CheckIn.fromJson(checkInJson(publicId: 'NEW')));

      expect(c.recentCheckIns.map((x) => x.publicId), [
        'NEW',
        'C0',
        'C1',
        'C2',
        'C3',
      ]);
    });
  });

  group('history lists', () {
    test(
      'All check-ins pages 20 at a time with the company day, without repeats',
      () async {
        answer(
          server(
            checkIns: [
              for (var i = 0; i < 23; i++) checkInJson(publicId: 'C$i'),
            ],
          ),
        );
        final c = CheckInsController(myStatusClientFor(backend, auth));
        addTearDown(c.dispose);

        await c.load();
        expect(c.items, hasLength(20));
        expect(c.hasMore, isTrue);
        expect(c.companyDay!.date, '2026-10-14');

        await c.loadMore();
        expect(c.items.map((x) => x.publicId), [
          for (var i = 0; i < 23; i++) 'C$i',
        ]);
        expect(c.hasMore, isFalse);
      },
    );

    test('All status changes pages too; a 403 is no profile', () async {
      answer(
        server(
          statuses: [
            for (var i = 0; i < 21; i++)
              statusJson(
                'busy',
                recordedAt:
                    '2026-10-14T01:${i.toString().padLeft(2, '0')}:00+00:00',
              ),
          ],
        ),
      );
      final s = StatusHistoryController(myStatusClientFor(backend, auth));
      addTearDown(s.dispose);
      await s.load();
      await s.loadMore();
      expect(s.items, hasLength(21));

      answer((r) async => jsonError(403));
      final none = StatusHistoryController(myStatusClientFor(backend, auth));
      addTearDown(none.dispose);
      await none.load();
      expect(none.status, PagedListStatus.noProfile);
    });
  });

  group('CheckInFlowController (R-19, R-20)', () {
    late FakeLocationService device;
    late DateTime now;

    CheckInFlowController flow() {
      final c = CheckInFlowController(
        myStatusClientFor(backend, auth),
        device,
        clock: () => now,
      );
      addTearDown(c.dispose);

      return c;
    }

    setUp(() {
      device = FakeLocationService();
      now = DateTime(2026, 10, 14, 9);
      device.reads = [fixAt(now)];
      answer((r) async => created(checkInJson(publicId: 'SENT')));
    });

    test(
      'already granted: straight to one position, no explanation, no prompt',
      () async {
        final c = flow();
        await c.start();

        expect(c.step, CheckInStep.ready);
        expect(c.fix!.latitude, 14.5547123);
        expect(device.requests, 0);
        expect(device.positionReads, 1);
        expect(device.lastTimeout, const Duration(seconds: 30));
        expect(requests, isEmpty, reason: 'nothing sent before Send');
      },
    );

    test('not yet granted: the explanation first, then Android asks', () async {
      device.current = LocationPermissionState.denied;
      final c = flow();

      await c.start();
      expect(c.step, CheckInStep.explaining);
      expect(device.requests, 0);
      expect(device.positionReads, 0);

      await c.continueAfterExplanation();
      expect(device.requests, 1);
      expect(c.step, CheckInStep.ready);
    });

    test(
      'denied at the prompt: the message and nothing read or sent',
      () async {
        device
          ..current = LocationPermissionState.denied
          ..onRequest = LocationPermissionState.denied;
        final c = flow();
        await c.start();
        await c.continueAfterExplanation();

        expect(c.step, CheckInStep.denied);
        expect(c.problem, 'Location permission is needed to check in.');
        expect(device.positionReads, 0);
        expect(requests, isEmpty);

        device.onRequest = LocationPermissionState.deniedForever;
        await c.start();
        await c.continueAfterExplanation();
        expect(c.step, CheckInStep.deniedForever);
        expect(c.problem, 'Location permission is needed to check in.');
        await c.openAppSettings();
        expect(device.appSettingsOpened, 1);
      },
    );

    test('denied for good already: no explanation, no prompt', () async {
      device.current = LocationPermissionState.deniedForever;
      final c = flow();
      await c.start();

      expect(c.step, CheckInStep.deniedForever);
      expect(device.requests, 0);
      expect(device.positionReads, 0);
    });

    test(
      'location turned off: the message, settings, nothing asked or read',
      () async {
        device.enabled = false;
        final c = flow();
        await c.start();

        expect(c.step, CheckInStep.serviceOff);
        expect(c.problem, 'Turn on location to check in.');
        expect(device.permissionChecks, 0);
        expect(device.positionReads, 0);
        await c.openLocationSettings();
        expect(device.locationSettingsOpened, 1);
      },
    );

    test('no position in time: the message; Try again reads again', () async {
      device.reads = [const LocationTimeoutException(), fixAt(now)];
      final c = flow();
      await c.start();

      expect(c.step, CheckInStep.timedOut);
      expect(
        c.problem,
        "Couldn't get your location. Try again, ideally outdoors or near a window.",
      );

      await c.start();
      expect(c.step, CheckInStep.ready);
      expect(device.positionReads, 2);
    });

    test('location lost while reading: the matching message', () async {
      device.reads = [const LocationServiceOffException()];
      final off = flow();
      await off.start();
      expect(off.step, CheckInStep.serviceOff);

      device
        ..reads = [const LocationPermissionException()]
        ..positionReads = 0;
      final denied = flow();
      await denied.start();
      expect(denied.step, CheckInStep.denied);
    });

    test('Send posts once, with the place and note and no status', () async {
      final c = flow();
      await c.start();

      expect(
        await c.send(locationLabel: ' Client site ', note: 'Gate B'),
        isTrue,
      );
      expect(c.step, CheckInStep.sent);
      expect(c.sent!.publicId, 'SENT');
      final body = jsonDecode(posts('/me/check-ins').single.body) as Map;
      expect(body, {
        'latitude': 14.5547123,
        'longitude': 121.0244456,
        'accuracy_meters': 9,
        'location_label': 'Client site',
        'note': 'Gate B',
      });
      expect(await c.send(), isFalse, reason: 'already sent');
      expect(posts('/me/check-ins'), hasLength(1));
    });

    test('a fix older than 2 minutes is read again before sending', () async {
      device.reads = [fixAt(now), fixAt(now, latitude: 10.0)];
      final c = flow();
      await c.start();

      now = now.add(const Duration(minutes: 2));
      await c.send();
      expect(
        device.positionReads,
        1,
        reason: 'exactly 2 minutes is still fresh',
      );

      device.reads = [fixAt(now), fixAt(now, latitude: 10.0)];
      device.positionReads = 0;
      final d = flow();
      await d.start();
      now = now.add(const Duration(minutes: 2, seconds: 1));
      await d.send();
      expect(device.positionReads, 2);
      expect(
        (jsonDecode(posts('/me/check-ins').last.body) as Map)['latitude'],
        10.0,
      );
    });

    test('a stale fix that can no longer be read sends nothing', () async {
      device.reads = [fixAt(now), const LocationTimeoutException()];
      final c = flow();
      await c.start();
      now = now.add(const Duration(minutes: 5));

      expect(await c.send(), isFalse);
      expect(c.step, CheckInStep.timedOut);
      expect(requests, isEmpty);
    });

    test(
      'offline at Send: the form stays with a message; a second Send works',
      () async {
        final c = flow();
        await c.start();
        answer((r) async => throw const NetworkFailure());

        expect(await c.send(locationLabel: 'Site'), isFalse);
        expect(c.step, CheckInStep.ready);
        expect(c.fix, isNotNull);
        expect(
          c.sendError,
          "Couldn't send your check-in. Check your connection and try again.",
        );

        answer((r) async => created(checkInJson(publicId: 'SENT')));
        expect(await c.send(locationLabel: 'Site'), isTrue);
        expect(c.sendError, isNull);
      },
    );

    test('a 422 shows the server message; a 403 is no profile', () async {
      final c = flow();
      await c.start();
      answer(
        (r) async => http.Response(
          jsonEncode({
            'message':
                'The note field must not be greater than 500 characters.',
            'errors': {
              'note': [
                'The note field must not be greater than 500 characters.',
              ],
            },
          }),
          422,
        ),
      );
      await c.send();
      expect(
        c.sendError,
        'The note field must not be greater than 500 characters.',
      );

      answer((r) async => jsonError(403));
      await c.send();
      expect(c.step, CheckInStep.noProfile);
      expect(c.problem, 'No staff profile is linked to this account.');
      expect(auth.status, AuthStatus.authenticated);
    });

    test('a double tap on Send posts once', () async {
      final c = flow();
      await c.start();
      final reply = Completer<http.Response>();
      answer((r) => reply.future);

      final first = c.send();
      await Future<void>.delayed(Duration.zero);
      expect(c.step, CheckInStep.sending);
      expect(await c.send(), isFalse);
      reply.complete(created(checkInJson()));
      expect(await first, isTrue);
      expect(posts('/me/check-ins'), hasLength(1));
    });

    test('Start is ignored while reading a position', () async {
      final pending = Completer<LocationFix>();
      device.reads = [pending];
      final c = flow();
      final starting = c.start();
      await Future<void>.delayed(Duration.zero);
      expect(c.step, CheckInStep.locating);

      await c.start();
      expect(device.positionReads, 1);
      pending.complete(fixAt(now));
      await starting;
      expect(c.step, CheckInStep.ready);
    });
  });
}

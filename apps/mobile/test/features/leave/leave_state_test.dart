import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/leave/state/leave_changes.dart';
import 'package:mobile/features/leave/state/leave_form_controller.dart';
import 'package:mobile/features/leave/state/leave_overview_controller.dart';
import 'package:mobile/features/leave/state/leave_request_controller.dart';
import 'package:mobile/features/leave/state/team_leave_controller.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

import '../../support/fake_backend.dart';
import '../../support/leave_fixtures.dart';
import '../../support/people_fixtures.dart' show jsonResponse;

/// Phase 31 Gate 2 — [LeaveApiClient] and the leave controllers over the
/// real `ApiClient` (spec R-1…R-11): exact requests, the company day,
/// confirmed writes sent once, the server's refusals in its own words, no
/// profile, and stale responses dropped.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late FakeLeaveServer server;
  late LeaveApiClient client;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    server = FakeLeaveServer();
    backend.onApi = server.handle;
    client = leaveClientFor(backend, auth);
  });

  String target(http.Request r) =>
      '${r.method} ${r.url.path.replaceFirst('/api/v1', '')}'
      '${r.url.hasQuery ? '?${r.url.query}' : ''}';

  group('LeaveApiClient', () {
    test('reads with explicit paging and filters', () async {
      await client.fetchMyRequests(page: 2);
      await client.fetchBalances();
      await client.fetchTeamRequests(filter: TeamLeaveFilter.all, perPage: 1);
      server.team = [leaveJson(publicId: 'T1')];
      await client.fetchTeamRequest('T1');
      await client.fetchMyRequest('01J0LEAVE0000000000000AAAA');

      expect(server.requests.map(target), [
        'GET /me/leave-requests?per_page=20&page=2',
        'GET /me/leave-balances',
        'GET /me/team-leave-requests?status=all&per_page=1&page=1',
        'GET /leave-requests/T1',
        'GET /me/leave-requests/01J0LEAVE0000000000000AAAA',
      ]);
    });

    test(
      'writes send exactly the API fields, trimmed, blank as null',
      () async {
        server.team = [leaveJson(publicId: 'T1'), leaveJson(publicId: 'T2')];
        await client.submit(
          const LeaveDraft(
            leaveTypeId: 'TYPE',
            startDate: '2026-10-20',
            endDate: '2026-10-21',
            reason: ' Trip ',
          ),
        );
        await client.cancel('01J0LEAVE0000000000000AAAA', reason: '   ');
        await client.approve('T1', note: ' Enjoy ');
        await client.reject('T2', reason: ' Busy week ');

        final writes = server.writes();
        expect(writes.map(target), [
          'POST /me/leave-requests',
          'POST /me/leave-requests/01J0LEAVE0000000000000AAAA/cancel',
          'POST /leave-requests/T1/approve',
          'POST /leave-requests/T2/reject',
        ]);
        expect(writes.map(server.bodyOf), [
          {
            'leave_type_id': 'TYPE',
            'start_date': '2026-10-20',
            'end_date': '2026-10-21',
            'reason': 'Trip',
          },
          {'reason': null},
          {'note': 'Enjoy'},
          {'reason': 'Busy week'},
        ]);
      },
    );

    test('a body that breaks the contract is a request failure', () async {
      server.override = (r) => http.Response('{"data":[{"year":"x"}]}', 200);

      await expectLater(
        client.fetchBalances(),
        throwsA(isA<ApiRequestException>()),
      );
    });
  });

  group('LeaveOverviewController', () {
    test('loads balances, my requests and the team summary together', () async {
      server
        ..canReview = true
        ..directReports = 2
        ..team = [leaveJson(publicId: 'T1'), leaveJson(publicId: 'T2')];
      final c = LeaveOverviewController(client);

      await c.load();

      expect(c.status, LeaveOverviewStatus.loaded);
      expect(c.balances, hasLength(2));
      expect(c.balanceYear, 2026);
      expect(c.requests.single.publicId, '01J0LEAVE0000000000000AAAA');
      expect(c.companyDay?.date, '2026-10-14');
      expect(c.hasTeam, isTrue);
      expect(c.teamPendingCount, 2);
      expect(
        server.requests.map(target),
        unorderedEquals([
          'GET /me/leave-balances',
          'GET /me/leave-requests?per_page=20&page=1',
          'GET /me/team-leave-requests?status=pending&per_page=1&page=1',
        ]),
      );
    });

    test('no team for someone without review rights or reports', () async {
      final c = LeaveOverviewController(client);
      await c.load();
      expect(c.hasTeam, isFalse);

      server
        ..canReview = true
        ..directReports = 0;
      await c.refresh();
      expect(c.hasTeam, isFalse);
    });

    test('a 403 is the no-profile state', () async {
      server.override = (r) =>
          jsonError(403, 'No staff record is linked to this account.');
      final c = LeaveOverviewController(client);

      await c.load();

      expect(c.status, LeaveOverviewStatus.noProfile);
      expect(backend.meCalls, greaterThan(0));
      expect(auth.currentToken, isNotNull);
    });

    test('several failed reads: one error state, nothing unhandled', () async {
      server.override = (r) => jsonError(500);
      final c = LeaveOverviewController(client);

      await c.load();
      // Let the other reads' failures settle.
      await pumpEventQueue();

      expect(c.status, LeaveOverviewStatus.error);
      expect(c.errorMessage, 'Something went wrong loading your leave.');
    });

    test('offline first load; a failed refresh keeps the content', () async {
      server.override = (r) => throw const NetworkFailure();
      final c = LeaveOverviewController(client);
      await c.load();
      expect(
        c.errorMessage,
        "Couldn't load your leave. Check your connection.",
      );

      server.override = null;
      await c.load();
      expect(c.status, LeaveOverviewStatus.loaded);

      server.override = (r) => jsonError(500);
      expect(await c.refresh(), isFalse);
      expect(c.status, LeaveOverviewStatus.loaded);
      expect(c.requests, hasLength(1));
    });

    test('load more appends the next page; a failure can be retried', () async {
      server.mine = [
        for (var i = 0; i < 25; i++)
          leaveJson(publicId: 'R${i.toString().padLeft(2, '0')}'),
      ];
      final c = LeaveOverviewController(client);
      await c.load();
      expect((c.requests.length, c.hasMore), (20, true));

      server.override = (r) =>
          r.url.path.endsWith('/me/leave-requests') ? jsonError(500) : null;
      await c.loadMore();
      expect((c.requests.length, c.loadMoreFailed), (20, true));

      server.override = null;
      await c.loadMore();
      expect(
        (c.requests.length, c.hasMore, c.loadMoreFailed),
        (25, false, false),
      );
      expect(c.requests.last.publicId, 'R24');
    });

    test('a load-more page from before a refresh is dropped', () async {
      server.mine = [for (var i = 0; i < 25; i++) leaveJson(publicId: 'R$i')];
      final c = LeaveOverviewController(client);
      await c.load();

      final gate = Completer<void>();
      final oldPage2 = jsonResponse(
        pageBody(
          [for (var i = 20; i < 25; i++) leaveJson(publicId: 'R$i')],
          page: 2,
          total: 25,
        ),
      );
      server.override = (r) async {
        if (r.url.queryParameters['page'] == '2') {
          await gate.future;

          return oldPage2;
        }

        return null;
      };
      final more = c.loadMore();
      await pumpEventQueue();
      server.mine = [leaveJson(publicId: 'FRESH')];
      await c.refresh();
      gate.complete();
      await more;

      expect(c.requests.map((r) => r.publicId), ['FRESH']);
    });
  });

  group('LeaveFormController', () {
    Future<LeaveFormController> ready({
      String? companyDate = '2026-10-14',
    }) async {
      final c = LeaveFormController(
        client,
        companyDate: companyDate,
        changes: LeaveChanges(),
      );
      await c.load();

      return c;
    }

    test('defaults both dates to the company day it was given', () async {
      final c = await ready();

      expect(c.status, LeaveFormStatus.ready);
      expect((c.startDate, c.endDate, c.days), ('2026-10-14', '2026-10-14', 1));
      expect(c.types.map((t) => t.leaveType.name), [
        'Annual Leave',
        'Unpaid Leave',
      ]);
      expect(c.isDirty, isFalse);
    });

    test('reads the company day when opened without one', () async {
      final c = await ready(companyDate: null);

      expect(c.companyDate, '2026-10-14');
      expect(c.startDate, '2026-10-14');
      expect(c.isDirty, isFalse);
      expect(
        server.requests.map(target),
        contains('GET /me/leave-requests?per_page=1&page=1'),
      );
    });

    test('a 403 while loading is the no-profile state', () async {
      server.override = (r) => jsonError(403);
      final c = await ready();

      expect(c.status, LeaveFormStatus.noProfile);
    });

    test('checks everything before sending, and sends nothing', () async {
      final c = await ready();
      c
        ..setStartDate('2026-12-30')
        ..setEndDate('2027-01-02')
        ..setReason(' a ');

      expect(await c.submit(), isFalse);
      expect(c.fieldErrors, {
        LeaveField.type: 'Choose a type of leave.',
        LeaveField.end: 'A request must stay within one calendar year. Make one request for each year.',
        LeaveField.reason: 'Give a reason (at least 3 characters).',
      });

      c.setEndDate('2026-12-01');
      expect(c.validate(), isFalse);
      expect(
        c.fieldErrors[LeaveField.end],
        'The last day cannot be before the first day.',
      );

      c.setReason('x' * 1001);
      c.validate();
      expect(c.fieldErrors[LeaveField.reason], 'Use at most 1000 characters.');
      expect(server.writes(), isEmpty);
    });

    test('a start after the end moves the end with it', () async {
      final c = await ready();
      c.setStartDate('2026-10-20');

      expect((c.endDate, c.days), ('2026-10-20', 1));
      c.setEndDate('2026-10-23');
      expect(c.days, 4);
    });

    test('past dates are allowed (sick leave)', () async {
      final c = await ready();
      c
        ..setType(c.types.first)
        ..setStartDate('2026-10-01')
        ..setEndDate('2026-10-02')
        ..setReason('Flu.');

      expect(c.validate(), isTrue);
    });

    test(
      'shows the balance only for a paid type in the balance year',
      () async {
        final c = await ready();
        expect(c.balanceHint, isNull);

        c.setType(c.types.first);
        expect(c.balanceHint, '10 days left in 2026');

        c.setStartDate('2027-01-04');
        expect(c.balanceHint, isNull);

        c
          ..setStartDate('2026-10-20')
          ..setType(c.types.last);
        expect(c.balanceHint, isNull);
      },
    );

    test('submits once and records the confirmed request', () async {
      final changes = LeaveChanges();
      final c = LeaveFormController(
        client,
        companyDate: '2026-10-14',
        changes: changes,
      );
      await c.load();
      c
        ..setType(c.types.first)
        ..setStartDate('2026-10-20')
        ..setEndDate('2026-10-23')
        ..setReason('  Family trip.  ');
      expect(c.isDirty, isTrue);

      final first = c.submit();
      final second = c.submit();
      expect(await second, isFalse);
      expect(await first, isTrue);

      expect(server.writes(), hasLength(1));
      expect(server.bodyOf(server.writes().single), {
        'leave_type_id': '01J0TYPEANNUAL000000000AAA',
        'start_date': '2026-10-20',
        'end_date': '2026-10-23',
        'reason': 'Family trip.',
      });
      expect(c.submitted?.status, LeaveStatus.pending);
      expect(changes.revision, 1);
      expect(changes.last?.publicId, '01J0LEAVENEW00000000000AAA');
      expect(c.isDirty, isFalse);
    });

    Future<LeaveFormController> filled() async {
      final c = await ready();
      c
        ..setType(c.types.first)
        ..setStartDate('2026-10-20')
        ..setReason('Trip.');

      return c;
    }

    test('a 422 is shown on its fields; others at the top', () async {
      server.override = (r) => r.method == 'POST'
          ? http.Response(
              '{"message":"Invalid.","errors":{"end_date":["Overlaps."],'
              '"leave_type_id":["This leave type is not active."]}}',
              422,
            )
          : null;
      final c = await filled();

      expect(await c.submit(), isFalse);
      expect(c.fieldErrors, {LeaveField.end: 'Overlaps.'});
      expect(c.generalError, 'This leave type is not active.');
      expect(c.reason, 'Trip.');
      expect(c.isDirty, isTrue);
    });

    test("a 409 shows the server's words", () async {
      server.override = (r) => r.method == 'POST'
          ? jsonError(409, 'Insufficient leave balance: 1 day(s) remaining.')
          : null;
      final c = await filled();

      expect(await c.submit(), isFalse);
      expect(c.generalError, 'Insufficient leave balance: 1 day(s) remaining.');
    });

    test('offline: nothing is retried and the input stays', () async {
      server.override = (r) =>
          r.method == 'POST' ? throw const NetworkFailure() : null;
      final c = await filled();

      expect(await c.submit(), isFalse);
      expect(
        c.generalError,
        "Couldn't send the request. Check your connection and try again.",
      );
      expect(server.writes(), hasLength(1));
      expect(c.startDate, '2026-10-20');
    });
  });

  group('LeaveRequestController — mine', () {
    LeaveRequestController mine(
      Map<String, dynamic> json, {
      String? companyDate = '2026-10-14',
      LeaveChanges? changes,
    }) {
      server.mine = [json];

      return LeaveRequestController(
        client,
        json['public_id'] as String,
        viewer: LeaveRequestViewer.mine,
        initial: LeaveRequest.fromJson(json),
        companyDay: companyDate == null
            ? null
            : ScheduleCompanyDay(
                date: companyDate,
                timezone: 'Asia/Manila',
                utcOffset: const Duration(hours: 8),
              ),
        changes: changes,
      );
    }

    test('Cancel is offered by the company day', () {
      expect(
        mine(leaveJson(status: 'approved', start: '2026-10-15')).canCancel,
        isTrue,
      );
      expect(
        mine(
          leaveJson(status: 'approved', start: '2026-10-14', end: '2026-10-15'),
        ).canCancel,
        isFalse,
      );
      expect(mine(leaveJson()).canCancel, isTrue);
      expect(mine(leaveJson(status: 'rejected')).canCancel, isFalse);
      expect(mine(leaveJson(), companyDate: null).canCancel, isFalse);
      expect(mine(leaveJson()).canDecide, isFalse);
    });

    test('loading reads the company day with the request', () async {
      final c = mine(
        leaveJson(status: 'approved', start: '2026-10-15'),
        companyDate: null,
      );

      await c.load();

      expect(c.companyDay?.date, '2026-10-14');
      expect(c.canCancel, isTrue);
    });

    test('cancel sends once with the reason and records the result', () async {
      final changes = LeaveChanges();
      final c = mine(leaveJson(), changes: changes);

      final first = c.cancel(reason: 'Plans changed.');
      expect(await c.cancel(), isFalse);
      expect(await first, isTrue);

      expect(server.writes(), hasLength(1));
      expect(server.bodyOf(server.writes().single), {
        'reason': 'Plans changed.',
      });
      expect(c.data?.status, LeaveStatus.cancelled);
      expect(c.data?.history.last.action, LeaveAction.cancelled);
      expect(changes.last?.status, LeaveStatus.cancelled);
      expect(c.canCancel, isFalse);
    });

    test("a 409 shows the server's words and reloads", () async {
      final changes = LeaveChanges();
      final json = leaveJson(status: 'approved', start: '2026-10-15');
      final c = mine(json, changes: changes);
      server.override = (r) {
        if (r.method == 'POST') {
          json['status'] = 'cancelled';

          return jsonError(
            409,
            'This leave request is no longer active and cannot be cancelled.',
          );
        }

        return null;
      };

      expect(await c.cancel(), isFalse);
      expect(
        c.actionMessage,
        'This leave request is no longer active and cannot be cancelled.',
      );
      expect(c.data?.status, LeaveStatus.cancelled);
      expect(changes.revision, 1);
    });

    test('offline cancel keeps the request as it was', () async {
      final c = mine(leaveJson());
      server.override = (r) =>
          r.method == 'POST' ? throw const NetworkFailure() : null;

      expect(await c.cancel(), isFalse);
      expect(
        c.actionMessage,
        "Couldn't send that. Check your connection and try again.",
      );
      expect(c.data?.status, LeaveStatus.pending);
    });

    test('both reads failing: one error state, nothing unhandled', () async {
      server.override = (r) => jsonError(500);
      final c = LeaveRequestController(
        client,
        'X',
        viewer: LeaveRequestViewer.mine,
      );

      await c.load();
      await pumpEventQueue();

      expect(c.errorMessage, 'Something went wrong loading this request.');
    });

    test('a 404 load is "no longer available"', () async {
      server.mine = [];
      final c = LeaveRequestController(
        client,
        'GONE',
        viewer: LeaveRequestViewer.mine,
      );

      await c.load();

      expect(c.status, LeaveRequestStatusView.error);
      expect(c.errorMessage, 'This request is no longer available.');
    });
  });

  group('LeaveRequestController — team', () {
    LeaveRequestController team(
      Map<String, dynamic> json, {
      LeaveChanges? changes,
    }) {
      server.team = [json];

      return LeaveRequestController(
        client,
        json['public_id'] as String,
        viewer: LeaveRequestViewer.team,
        initial: LeaveRequest.fromJson(json),
        changes: changes,
      );
    }

    test('approve with a note', () async {
      final changes = LeaveChanges();
      final c = team(leaveJson(publicId: 'T1'), changes: changes);
      expect((c.canDecide, c.canCancel), (true, false));

      expect(await c.approve(note: 'Enjoy.'), isTrue);

      expect(server.writes().map(target), ['POST /leave-requests/T1/approve']);
      expect(c.data?.status, LeaveStatus.approved);
      expect(c.canDecide, isFalse);
      expect(changes.last?.status, LeaveStatus.approved);
    });

    test('reject with a reason; a 422 shows the reason error', () async {
      final c = team(leaveJson(publicId: 'T1'));
      server.override = (r) => r.method == 'POST'
          ? http.Response(
              '{"message":"Invalid.","errors":{"reason":["The reason field must be at least 3 characters."]}}',
              422,
            )
          : null;

      expect(await c.reject(reason: 'no'), isFalse);
      expect(
        c.actionMessage,
        'The reason field must be at least 3 characters.',
      );

      server.override = null;
      expect(await c.reject(reason: 'Busy week.'), isTrue);
      expect(c.data?.status, LeaveStatus.rejected);
      expect(c.data?.history.last.note, 'Busy week.');
    });

    test('already decided elsewhere: 409, reloaded, buttons gone', () async {
      final json = leaveJson(publicId: 'T1');
      final c = team(json);
      server.override = (r) {
        if (r.method == 'POST') {
          json['status'] = 'approved';

          return jsonError(
            409,
            'Only a pending leave request can be approved.',
          );
        }

        return null;
      };

      expect(await c.approve(), isFalse);
      expect(c.actionMessage, 'Only a pending leave request can be approved.');
      expect(c.data?.status, LeaveStatus.approved);
      expect(c.canDecide, isFalse);
    });

    test('team detail loads without asking for the company day', () async {
      final c = team(leaveJson(publicId: 'T1'));
      await c.refresh();

      expect(server.requests.map(target), ['GET /leave-requests/T1']);
    });
  });

  group('TeamLeaveController', () {
    test('pending by default; a filter change reloads from page 1', () async {
      server
        ..canReview = true
        ..directReports = 1
        ..team = [
          leaveJson(publicId: 'P1'),
          leaveJson(publicId: 'A1', status: 'approved'),
        ];
      final c = TeamLeaveController(client);

      await c.load();
      expect(c.items.map((r) => r.publicId), ['P1']);
      expect(c.pendingCount, 1);

      await c.setFilter(TeamLeaveFilter.approved);
      expect(c.items.map((r) => r.publicId), ['A1']);
      expect(c.pendingCount, 1);
      expect(
        server.requests.map(target).last,
        'GET /me/team-leave-requests?status=approved&per_page=20&page=1',
      );
    });

    test('a slow page for the old filter never replaces the new one', () async {
      server
        ..canReview = true
        ..team = [
          leaveJson(publicId: 'P1'),
          leaveJson(publicId: 'A1', status: 'approved'),
        ];
      final gate = Completer<void>();
      server.override = (r) async {
        if (r.url.queryParameters['status'] == 'pending') {
          await gate.future;
        }

        return null;
      };
      final c = TeamLeaveController(client);

      final first = c.load();
      await pumpEventQueue();
      await c.setFilter(TeamLeaveFilter.approved);
      gate.complete();
      await first;

      expect(c.status, PagedListStatus.loaded);
      expect(c.items.map((r) => r.publicId), ['A1']);
    });
  });
}

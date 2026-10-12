import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/features/leave/domain/leave.dart';

import '../../support/leave_fixtures.dart';

/// Phase 31 Gate 2 — strict parsing of the Phase 13 leave shapes, the
/// company-day cancel rule (R-2b/R-6), and the day count (R-5).
void main() {
  group('LeaveRequest.fromJson', () {
    test('parses every field', () {
      final r = LeaveRequest.fromJson(
        leaveJson(
          history: [
            historyJson('submitted', by: person('S1', 'Ada Lovelace')),
            historyJson('approved', note: 'Enjoy.'),
          ],
        ),
      );

      expect(r.publicId, '01J0LEAVE0000000000000AAAA');
      expect(r.staff?.displayName, 'Ada Lovelace');
      expect(r.leaveType.name, 'Annual Leave');
      expect(r.leaveType.code, 'AL');
      expect(r.leaveType.isPaid, isTrue);
      expect(
        (r.startDate, r.endDate, r.totalDays),
        ('2026-10-20', '2026-10-22', 3),
      );
      expect(r.reason, 'Family trip.');
      expect(r.status, LeaveStatus.pending);
      expect(r.history.map((h) => h.action), [
        LeaveAction.submitted,
        LeaveAction.approved,
      ]);
      expect(r.history.first.actedBy?.displayName, 'Ada Lovelace');
      expect(r.history.last.actedBy, isNull);
      expect(r.history.last.note, 'Enjoy.');
      expect(r.history.last.createdAt, DateTime.utc(2026, 10, 10, 1));
    });

    test('keeps an unknown status or action instead of failing', () {
      final r = LeaveRequest.fromJson(
        leaveJson(status: 'withdrawn', history: [historyJson('escalated')]),
      );

      expect(r.status, LeaveStatus.unknown);
      expect(r.history.single.action, LeaveAction.unknown);
    });

    test('a missing history is an empty one', () {
      final json = leaveJson()..remove('history');

      expect(LeaveRequest.fromJson(json).history, isEmpty);
    });

    for (final (name, mutate)
        in <(String, void Function(Map<String, dynamic>))>[
          ('missing public_id', (j) => j.remove('public_id')),
          ('bad start_date', (j) => j['start_date'] = '20/10/2026'),
          ('end before start', (j) => j['end_date'] = '2026-10-01'),
          ('total_days as text', (j) => j['total_days'] = '3'),
          ('missing leave_type', (j) => j.remove('leave_type')),
          (
            'is_paid missing',
            (j) => j['leave_type'] = {...annualType}..remove('is_paid'),
          ),
          ('status not a string', (j) => j['status'] = 1),
          (
            'bad history instant',
            (j) => j['history'] = [
              historyJson('submitted', createdAt: 'yesterday'),
            ],
          ),
        ]) {
      test('rejects $name', () {
        final json = leaveJson();
        mutate(json);

        expect(() => LeaveRequest.fromJson(json), throwsFormatException);
      });
    }
  });

  group('canSelfCancel (company day)', () {
    LeaveRequest at(String status, String start) => LeaveRequest.fromJson(
      leaveJson(status: status, start: start, end: start),
    );

    test('pending: always, even once started', () {
      expect(at('pending', '2026-10-01').canSelfCancel('2026-10-14'), isTrue);
    });

    test('approved: only before its first company day', () {
      expect(at('approved', '2026-10-15').canSelfCancel('2026-10-14'), isTrue);
      expect(at('approved', '2026-10-14').canSelfCancel('2026-10-14'), isFalse);
      expect(at('approved', '2026-10-13').canSelfCancel('2026-10-14'), isFalse);
    });

    test('rejected, cancelled, unknown: never', () {
      for (final s in ['rejected', 'cancelled', 'odd']) {
        expect(at(s, '2026-12-01').canSelfCancel('2026-10-14'), isFalse);
      }
    });
  });

  group('LeaveBalance.fromJson', () {
    test('paid', () {
      final b = LeaveBalance.fromJson(balanceJson(notes: 'Carry-over'));

      expect(b.isPaid, isTrue);
      expect(b.year, 2026);
      expect(
        (b.allocatedDays, b.usedDays, b.pendingDays, b.remainingDays),
        (15, 3, 2, 10),
      );
      expect(b.notes, 'Carry-over');
    });

    test('unpaid has no allocation or remainder', () {
      final b = LeaveBalance.fromJson(balanceJson(type: unpaidType));

      expect(b.isPaid, isFalse);
      expect(b.allocatedDays, isNull);
      expect(b.remainingDays, isNull);
      expect(b.leaveType.code, isNull);
    });

    test('a paid balance without days is rejected', () {
      final json = balanceJson()..['remaining_days'] = null;

      expect(() => LeaveBalance.fromJson(json), throwsFormatException);
    });
  });

  group('pages', () {
    test('my requests carry the company day', () {
      final page = MyLeaveRequestsPage.fromJson(pageBody([leaveJson()]));

      expect(page.companyDay.date, '2026-10-14');
      expect(page.companyDay.utcOffset, const Duration(hours: 8));
      expect(page.page.items, hasLength(1));
    });

    test('my requests without the company day are rejected', () {
      final body = pageBody([]);
      (body['meta'] as Map).remove('company_day');

      expect(() => MyLeaveRequestsPage.fromJson(body), throwsFormatException);
    });

    test('team page: review rights, counts, hasTeam', () {
      TeamLeavePage team(bool canReview, int reports) => TeamLeavePage.fromJson(
        pageBody(
          [],
          extraMeta: {
            'can_review': canReview,
            'direct_reports_count': reports,
            'pending_count': 4,
          },
        ),
      );

      expect(team(true, 2).hasTeam, isTrue);
      expect(team(true, 2).pendingCount, 4);
      expect(team(true, 0).hasTeam, isFalse);
      expect(team(false, 0).hasTeam, isFalse);
    });

    test('team page without can_review is rejected', () {
      expect(
        () => TeamLeavePage.fromJson(
          pageBody(
            [],
            extraMeta: {'direct_reports_count': 0, 'pending_count': 0},
          ),
        ),
        throwsFormatException,
      );
    });
  });

  test('calendarDaysInclusive counts weekends and both ends', () {
    expect(calendarDaysInclusive('2026-10-16', '2026-10-19'), 4); // Fri–Mon
    expect(calendarDaysInclusive('2026-10-16', '2026-10-16'), 1);
    expect(calendarDaysInclusive('2026-10-16', '2026-10-15'), 0);
    expect(calendarDaysInclusive('2026-02-27', '2026-03-02'), 4);
  });

  test('LeaveDraft trims the reason', () {
    expect(
      const LeaveDraft(
        leaveTypeId: 'T',
        startDate: '2026-10-20',
        endDate: '2026-10-21',
        reason: '  Trip  ',
      ).toJson(),
      {
        'leave_type_id': 'T',
        'start_date': '2026-10-20',
        'end_date': '2026-10-21',
        'reason': 'Trip',
      },
    );
  });
}

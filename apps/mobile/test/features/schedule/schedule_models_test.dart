import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/features/schedule/domain/schedule.dart';

import '../../support/schedule_fixtures.dart';

/// Phase 30A Gate 2 — the schedule models and day grouping (spec §5.2):
/// strict parsing, company-time days from the server's offset, multi-day
/// and boundary items.
void main() {
  group('parsing', () {
    test('a full response parses: company day, range and items', () {
      final s = MySchedule.fromJson(
        myScheduleJson([
          scheduleItemJson(
            project: {'public_id': 'P1', 'name': 'Plant Room'},
            description: 'Bring the keys.',
          ),
          manilaTaskJson('2026-10-15'),
          scheduleItemJson(
            source: 'leave',
            publicId: 'L1',
            title: 'Vacation leave',
            isAllDay: true,
            status: 'approved',
          ),
        ]),
      );

      expect(s.companyDay.date, '2026-10-14');
      expect(s.companyDay.timezone, 'Asia/Manila');
      expect(s.companyDay.utcOffset, const Duration(hours: 8));
      expect(s.from, '2026-10-14');
      expect(s.to, '2026-10-20');
      expect(s.hasProfile, isTrue);
      final entry = s.items![0];
      expect(entry.source, ScheduleSource.scheduleEntry);
      expect(entry.activityType, 'meeting');
      expect(entry.project!.name, 'Plant Room');
      expect(entry.description, 'Bring the keys.');
      expect(entry.startsAt, DateTime.utc(2026, 10, 14, 1));
      expect(entry.startsAt.isUtc, isTrue);
      expect(s.items![1].source, ScheduleSource.task);
      expect(s.items![1].taskStatus, 'todo');
      expect(s.items![2].source, ScheduleSource.leave);
      expect(s.items![2].status, 'approved');
    });

    test('items null is the no-profile response', () {
      expect(MySchedule.fromJson(myScheduleJson(null)).hasProfile, isFalse);
    });

    test('an unknown source, a bad date, a bad instant or a missing field '
        'is a FormatException', () {
      for (final json in [
        myScheduleJson([scheduleItemJson(source: 'project_milestone')]),
        myScheduleJson([], from: '14/10/2026'),
        myScheduleJson([scheduleItemJson(startsAt: 'soon')]),
        myScheduleJson([
          {...scheduleItemJson(), 'title': null},
        ]),
        myScheduleJson([], companyDay: {...manilaDay, 'utc_offset': '8'}),
      ]) {
        expect(() => MySchedule.fromJson(json), throwsFormatException);
      }
    });

    test('an entry detail parses its people; no participants is empty', () {
      final e = ScheduleEntryDetail.fromJson(scheduleEntryJson());

      expect(e.title, 'Site visit');
      expect(e.activityType, 'client_visit');
      expect(e.creator!.displayName, 'Ada Lovelace');
      expect(e.participants.single.displayName, 'Grace Hopper');
      expect(e.project!.name, 'Plant Room');
      expect(
        ScheduleEntryDetail.fromJson(
          scheduleEntryJson(participants: <Object>[]),
        ).participants,
        isEmpty,
      );
    });
  });

  group('company days', () {
    const day = ScheduleCompanyDay(
      date: '2026-10-14',
      timezone: 'Asia/Manila',
      utcOffset: Duration(hours: 8),
    );

    test('a Manila day is 16:00 UTC the day before to 16:00 UTC', () {
      final (start, end) = day.boundsOf('2026-10-14');

      expect(start, DateTime.utc(2026, 10, 13, 16));
      expect(end, DateTime.utc(2026, 10, 14, 16));
      expect(
        day.toCompanyTime(DateTime.utc(2026, 10, 13, 23)),
        DateTime.utc(2026, 10, 14, 7),
      );
    });

    test('shiftDate does calendar arithmetic across months and years', () {
      expect(shiftDate('2026-10-31', 1), '2026-11-01');
      expect(shiftDate('2026-12-30', 7), '2027-01-06');
      expect(shiftDate('2026-03-01', -1), '2026-02-28');
    });

    List<List<String>> titlesByDay(List<Map<String, dynamic>> items) =>
        groupByDay(
          MySchedule.fromJson(myScheduleJson(items)).items!,
          day,
          '2026-10-13',
          '2026-10-16',
        ).map((d) => d.items.map((i) => i.title).toList()).toList();

    test('an early-morning Manila meeting falls on its Manila day', () {
      // 07:00–07:30 Manila on 14 Oct = 23:00–23:30 UTC on 13 Oct.
      expect(
        titlesByDay([
          scheduleItemJson(
            title: 'Early',
            startsAt: '2026-10-13T23:00:00+00:00',
            endsAt: '2026-10-13T23:30:00+00:00',
          ),
        ]),
        [
          [],
          ['Early'],
          [],
          [],
        ],
      );
    });

    test('a multi-day entry appears on every day it covers; one ending at '
        'midnight does not spill into the next day', () {
      expect(
        titlesByDay([
          // 22:00 Manila on 13 Oct to 09:00 Manila on 15 Oct.
          scheduleItemJson(
            title: 'Conference',
            startsAt: '2026-10-13T14:00:00+00:00',
            endsAt: '2026-10-15T01:00:00+00:00',
          ),
          // 23:00 to 00:00 Manila, 13→14 Oct.
          scheduleItemJson(
            title: 'Late call',
            publicId: 'B',
            startsAt: '2026-10-13T15:00:00+00:00',
            endsAt: '2026-10-13T16:00:00+00:00',
          ),
        ]),
        [
          ['Conference', 'Late call'],
          ['Conference'],
          ['Conference'],
          [],
        ],
      );
    });

    test(
      'an all-day task sits on its own day only; days keep server order',
      () {
        expect(
          titlesByDay([
            manilaTaskJson('2026-10-15', title: 'Z task'),
            manilaTaskJson('2026-10-15', title: 'A task', id: 'T2'),
          ]),
          [
            [],
            [],
            ['Z task', 'A task'],
            [],
          ],
        );
      },
    );

    test('a zero-length item at the start of a day belongs to that day', () {
      expect(
        titlesByDay([
          scheduleItemJson(
            title: 'Reminder',
            startsAt: '2026-10-14T16:00:00+00:00',
            endsAt: '2026-10-14T16:00:00+00:00',
          ),
        ]),
        [
          [],
          [],
          ['Reminder'],
          [],
        ],
      );
    });
  });
}

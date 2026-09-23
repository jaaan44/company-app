import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/features/home/domain/home_summary.dart';
import 'package:mobile/features/home/presentation/home_formatting.dart';

import '../../support/home_fixtures.dart';

/// Phase 27 Gate 3 — parsing `GET /me/home` (spec §6.2) and the
/// company-timezone display helpers (spec §8).
void main() {
  group('HomeSummary.fromJson', () {
    test('parses a complete populated response', () {
      final summary = HomeSummary.fromJson(
        homeDataJson(
          staff: staffJson(team: 'Night crew', preferredName: 'Addie'),
        ),
      );

      expect(summary.user.name, 'Ada Lovelace');
      expect(summary.user.role, 'staff');
      expect(summary.hasEmployeeProfile, isTrue);
      expect(summary.staff!.greetingName, 'Addie');
      expect(summary.staff!.positionTitle, 'Field Technician');
      expect(summary.staff!.departmentName, 'Operations');
      expect(summary.staff!.teamName, 'Night crew');
      expect(summary.tasks!.open, 4);
      expect(summary.tasks!.overdue, 1);
      expect(summary.tasks!.dueToday, 2);
      expect(summary.unreadMessages, 3);
      expect(summary.unreadNotifications, 5);
      expect(summary.announcements!.single.title, 'Office closed Friday');
      expect(
        summary.announcements!.single.publishedAt,
        DateTime.utc(2026, 9, 22, 9),
      );
    });

    test('parses company_day, including a non-UTC offset', () {
      final day = HomeSummary.fromJson(homeDataJson()).companyDay;

      expect(day.date, DateTime.utc(2026, 9, 24));
      expect(day.timezone, 'Asia/Tokyo');
      expect(day.startsAt, DateTime.utc(2026, 9, 23, 15));
      expect(day.endsAt, DateTime.utc(2026, 9, 24, 14, 59, 59));
      expect(day.utcOffset, const Duration(hours: 9));
      expect(
        day.toCompanyTime(DateTime.utc(2026, 9, 24)),
        DateTime.utc(2026, 9, 24, 9),
      );
    });

    test('parses negative and half-hour offsets', () {
      expect(parseUtcOffset('-05:30'), const Duration(hours: -5, minutes: -30));
      expect(parseUtcOffset('+00:00'), Duration.zero);
      expect(() => parseUtcOffset('0900'), throwsFormatException);
    });

    test('parses a schedule-entry Today item and a task Today item in server order', () {
      final today = HomeSummary.fromJson(homeDataJson(todayTotal: 7)).today!;

      expect(today.totalCount, 7);
      expect(today.moreCount, 5);
      final entry = today.items[0];
      expect(entry.kind, TodayItemKind.scheduleEntry);
      expect(entry.activityType, 'client_visit');
      expect(entry.taskStatus, isNull);
      expect(entry.isAllDay, isFalse);
      expect(entry.startsAt, DateTime.utc(2026, 9, 24));
      final task = today.items[1];
      expect(task.kind, TodayItemKind.task);
      expect(task.taskStatus, 'in_progress');
      expect(task.activityType, isNull);
      expect(task.isAllDay, isTrue);
    });

    test('keeps nullable profile fields and announcement dates null', () {
      final summary = HomeSummary.fromJson(
        homeDataJson(
          staff: staffJson(position: null, department: null),
          announcements: [announcementJson(publishedAt: null)],
        ),
      );

      expect(summary.staff!.positionTitle, isNull);
      expect(summary.staff!.departmentName, isNull);
      expect(summary.staff!.teamName, isNull);
      expect(summary.staff!.preferredName, isNull);
      expect(summary.staff!.greetingName, 'Ada');
      expect(summary.announcements!.single.publishedAt, isNull);
    });

    test('a no-profile response keeps every Staff-dependent section null (never zero)', () {
      final summary = HomeSummary.fromJson(
        homeDataJson(staff: null, unreadNotifications: 2),
      );

      expect(summary.hasEmployeeProfile, isFalse);
      expect(summary.staff, isNull);
      expect(summary.today, isNull);
      expect(summary.tasks, isNull);
      expect(summary.unreadMessages, isNull);
      expect(summary.announcements, isNull);
      expect(summary.unreadNotifications, 2);
      expect(summary.user.name, 'Ada Lovelace');
    });

    test('empty collections parse as empty', () {
      final summary = HomeSummary.fromJson(
        homeDataJson(todayItems: [], announcements: []),
      );

      expect(summary.today!.items, isEmpty);
      expect(summary.today!.moreCount, 0);
      expect(summary.announcements, isEmpty);
    });

    for (final (label, mutate)
        in <(String, void Function(Map<String, dynamic>))>[
          ('missing user', (d) => d.remove('user')),
          ('missing company_day', (d) => d.remove('company_day')),
          ('missing notifications', (d) => d.remove('notifications')),
          (
            'a string count',
            (d) => d['tasks'] = {
              'open_count': '4',
              'overdue_count': 1,
              'due_today_count': 2,
            },
          ),
          (
            'an unknown Today source_type',
            (d) => (d['today'] as Map)['items'] = [
              {...scheduleItemJson(), 'source_type': 'leave'},
            ],
          ),
          (
            'a malformed instant',
            (d) => (d['today'] as Map)['items'] = [
              scheduleItemJson(startsAt: 'yesterday'),
            ],
          ),
          (
            'a malformed company date',
            (d) => d['company_day'] = companyDayJson(date: '24/09/2026'),
          ),
        ]) {
      test('rejects $label with a FormatException', () {
        final data = homeDataJson();
        mutate(data);

        expect(() => HomeSummary.fromJson(data), throwsFormatException);
      });
    }
  });

  group('display helpers (company timezone, not device timezone)', () {
    final day = HomeSummary.fromJson(homeDataJson()).companyDay;

    TodayItem entry(String startsAt, String endsAt, {bool allDay = false}) =>
        TodayItem.fromJson(
          scheduleItemJson(
            startsAt: startsAt,
            endsAt: endsAt,
            isAllDay: allDay,
          ),
        );

    test('formats the company date heading from the server date', () {
      expect(formatDayMonth(day.date), 'Thu 24 Sep');
      expect(formatDate(DateTime.utc(2026, 9, 22)), '22 Sep 2026');
    });

    test('timed items show company-time HH:mm ranges', () {
      expect(
        formatTodayTime(
          entry('2026-09-24T00:00:00Z', '2026-09-24T01:30:00Z'),
          day,
        ),
        '09:00 – 10:30',
      );
    });

    test('all-day items read "All day"', () {
      final task = TodayItem.fromJson(taskItemJson());

      expect(formatTodayTime(task, day), 'All day');
      expect(todayItemLabel(task), 'Task due · In progress');
    });

    test('multi-day parts outside the company day show from/until dates', () {
      expect(
        formatTodayTime(
          entry('2026-09-22T23:00:00Z', '2026-09-24T01:00:00Z'),
          day,
        ),
        'From Wed 23 Sep – 10:00',
      );
      expect(
        formatTodayTime(
          entry('2026-09-24T09:00:00Z', '2026-09-25T02:00:00Z'),
          day,
        ),
        '18:00 – until Fri 25 Sep',
      );
    });

    test('labels every known activity type and task status', () {
      expect(activityTypeLabel('meeting'), 'Meeting');
      expect(activityTypeLabel('client_visit'), 'Client visit');
      expect(activityTypeLabel('service_appointment'), 'Service appointment');
      expect(activityTypeLabel('company_event'), 'Company event');
      expect(activityTypeLabel('training'), 'Training');
      expect(activityTypeLabel('internal_activity'), 'Internal activity');
      expect(activityTypeLabel('other'), 'Other');
      expect(activityTypeLabel('new_kind'), 'New kind');
      expect(taskStatusLabel('todo'), 'To do');
      expect(taskStatusLabel('in_progress'), 'In progress');
      expect(taskStatusLabel('blocked'), 'Blocked');
    });
  });
}

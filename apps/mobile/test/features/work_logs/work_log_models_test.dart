import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/features/work_logs/domain/work_log.dart';

import '../../support/work_log_fixtures.dart';

/// Phase 29B Gate 2 — the work-log models (spec §6.3–§6.4): strict
/// parsing of `WorkLogResource`, `/me/work-logs` with `meta.company_day`
/// (R-12), projects for the picker (R-14), and duration formatting.
void main() {
  group('WorkLog.fromJson', () {
    test('parses a task log, with the task as its label', () {
      final log = WorkLog.fromJson(
        workLogJson('L1', taskTitle: 'Replace pump seal', minutes: 75),
      );

      expect(log.publicId, 'L1');
      expect(log.workDate, '2026-10-10');
      expect(log.durationMinutes, 75);
      expect(log.description, 'Plant room check.');
      expect(log.task!.name, 'Replace pump seal');
      expect(log.project!.name, 'UAT Plant Room');
      expect(log.targetLabel, 'Replace pump seal');
      expect(log.createdAt, DateTime.utc(2026, 10, 10, 1));
    });

    test('a project log has no task and is labelled by its project', () {
      final log = WorkLog.fromJson(workLogJson('L1'));

      expect(log.task, isNull);
      expect(log.targetLabel, 'UAT Plant Room');
    });

    test('an independent task log has a task and no project', () {
      final log = WorkLog.fromJson(
        workLogJson('L1', taskTitle: 'Call the supplier', projectName: null),
      );

      expect(log.project, isNull);
      expect(log.targetLabel, 'Call the supplier');
    });

    for (final (label, mutate)
        in <(String, void Function(Map<String, dynamic>))>[
          ('missing description', (j) => j.remove('description')),
          (
            'non-date work_date',
            (j) => j['work_date'] = '2026-10-10T00:00:00Z',
          ),
          ('string duration', (j) => j['duration_minutes'] = '90'),
          ('neither task nor project', (j) => j['project'] = null),
          ('malformed task', (j) => j['task'] = {'public_id': 'T'}),
        ]) {
      test('$label is a FormatException', () {
        final json = workLogJson('L1');
        mutate(json);

        expect(() => WorkLog.fromJson(json), throwsFormatException);
      });
    }
  });

  group('MyWorkLogsPage.fromJson', () {
    test('parses items, paging and the company day', () {
      final page = MyWorkLogsPage.fromJson(
        workLogsBody(
          [workLogJson('L1'), workLogJson('L2')],
          page: 1,
          lastPage: 2,
        ),
      );

      expect(page.items.map((l) => l.publicId), ['L1', 'L2']);
      expect(page.companyDay.date, '2026-10-10');
      expect(page.companyDay.timezone, 'Asia/Manila');
      expect(page.hasMore, isTrue);
    });

    test('a missing or malformed company day is a FormatException', () {
      final missing = workLogsBody([]);
      (missing['meta'] as Map).remove('company_day');
      final malformed = workLogsBody([], date: 'today');

      expect(() => MyWorkLogsPage.fromJson(missing), throwsFormatException);
      expect(() => MyWorkLogsPage.fromJson(malformed), throwsFormatException);
    });
  });

  group('MemberProject', () {
    test('parses and knows when it is closed', () {
      for (final (status, closed) in [
        ('planned', false),
        ('active', false),
        ('on_hold', false),
        ('completed', true),
        ('cancelled', true),
      ]) {
        final p = MemberProject.fromJson(
          projectJson('P', status: status, code: 'C-1'),
        );
        expect(p.isClosed, closed, reason: status);
        expect(p.projectCode, 'C-1');
      }
    });

    test('a malformed project is a FormatException', () {
      expect(
        () => MemberProject.fromJson({'public_id': 'P'}),
        throwsFormatException,
      );
    });
  });

  test('targets name their request field', () {
    expect(
      const TaskTarget(publicId: 'T1', label: 'x').requestField,
      isA<MapEntry<String, String>>()
          .having((e) => e.key, 'key', 'task_id')
          .having((e) => e.value, 'value', 'T1'),
    );
    expect(
      const ProjectTarget(publicId: 'P1', label: 'x').requestField.key,
      'project_id',
    );
  });

  test('formatDuration', () {
    expect(formatDuration(1), '1 min');
    expect(formatDuration(45), '45 min');
    expect(formatDuration(60), '1 h');
    expect(formatDuration(90), '1 h 30 min');
    expect(formatDuration(1440), '24 h');
  });

  test('isApiDate', () {
    expect(isApiDate('2026-10-10'), isTrue);
    expect(isApiDate('2026-10-10T00:00'), isFalse);
    expect(isApiDate('10/10/2026'), isFalse);
  });
}

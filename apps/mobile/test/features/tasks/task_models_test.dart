import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/features/tasks/domain/task_item.dart';

import '../../support/task_fixtures.dart';

/// Phase 29A Gate 2 — the Tasks models (spec §5.1/§5.3): strict parsing of
/// `TaskResource` and `/me/tasks`, statuses (R-4), and the due state, which
/// never uses the device clock.
void main() {
  group('TaskItem.fromJson', () {
    test('parses every field', () {
      final task = TaskItem.fromJson(
        taskJson(
          status: 'in_progress',
          priority: 'urgent',
          completedAt: '2026-09-23T03:00:00+00:00',
          flags: true,
          isOverdue: true,
        ),
      );

      expect(task.publicId, '01TASK00000000000000000001');
      expect(task.title, 'Replace the lobby lights');
      expect(task.description, 'Both floors.');
      expect(task.status, TaskStatus.inProgress);
      expect(task.priority, TaskPriority.urgent);
      expect(task.dueDate, '2026-09-30');
      expect(task.completedAt, DateTime.utc(2026, 9, 23, 3));
      expect(task.project!.name, 'Fit-out');
      expect(task.assignee!.name, 'Ada Lovelace');
      expect(task.createdBy!.name, 'Grace Hopper');
      expect(task.createdAt, DateTime.utc(2026, 9, 20, 1));
      expect(task.updatedAt, DateTime.utc(2026, 9, 21, 2));
      expect(task.isOverdue, isTrue);
      expect(task.isDueToday, isFalse);
    });

    test('nullable fields stay null; flags are absent outside /me/tasks', () {
      final json = taskJson(description: null, dueDate: null, project: null)
        ..['assignee'] = null
        ..['created_by'] = null;

      final task = TaskItem.fromJson(json);

      expect(task.description, isNull);
      expect(task.dueDate, isNull);
      expect(task.project, isNull);
      expect(task.assignee, isNull);
      expect(task.createdBy, isNull);
      expect(task.isOverdue, isNull);
      expect(task.isDueToday, isNull);
    });

    for (final (label, mutate)
        in <(String, void Function(Map<String, dynamic>))>[
          ('missing title', (j) => j.remove('title')),
          ('unknown status', (j) => j['status'] = 'archived'),
          ('unknown priority', (j) => j['priority'] = 'critical'),
          ('non-date due_date', (j) => j['due_date'] = '2026-09-30T00:00:00Z'),
          ('wrong-typed flag', (j) => j['is_overdue'] = 'yes'),
          ('malformed project', (j) => j['project'] = {'public_id': 'P'}),
        ]) {
      test('$label is a FormatException', () {
        final json = taskJson();
        mutate(json);

        expect(() => TaskItem.fromJson(json), throwsFormatException);
      });
    }
  });

  group('TaskStatus', () {
    test('wire values round-trip and closed means completed or cancelled', () {
      for (final status in TaskStatus.values) {
        expect(TaskStatus.fromWire(status.wireValue), status);
      }
      expect(TaskStatus.values.where((s) => s.isClosed), [
        TaskStatus.completed,
        TaskStatus.cancelled,
      ]);
    });

    test('the app never offers Cancelled (R-4)', () {
      expect(TaskStatus.selectable, [
        TaskStatus.todo,
        TaskStatus.inProgress,
        TaskStatus.blocked,
        TaskStatus.completed,
      ]);
      expect(TaskStatus.inProgress.label, 'In progress');
    });
  });

  group('dueState', () {
    TaskItem task({
      String status = 'todo',
      String? due = '2026-09-24',
      bool? overdue,
      bool? today,
    }) {
      final json = taskJson(status: status, dueDate: due);
      if (overdue != null) json['is_overdue'] = overdue;
      if (today != null) json['is_due_today'] = today;

      return TaskItem.fromJson(json);
    }

    test('uses the server flags when present', () {
      expect(
        task(overdue: true, today: false).dueState(),
        TaskDueState.overdue,
      );
      expect(
        task(overdue: false, today: true).dueState(),
        TaskDueState.dueToday,
      );
      expect(
        task(overdue: false, today: false).dueState(),
        TaskDueState.notDue,
      );
      // The flags win over a company date that disagrees.
      expect(
        task(overdue: false, today: true).dueState(companyDate: '2026-09-25'),
        TaskDueState.dueToday,
      );
    });

    test('without flags, compares with the server-reported company date', () {
      expect(task().dueState(companyDate: '2026-09-25'), TaskDueState.overdue);
      expect(task().dueState(companyDate: '2026-09-24'), TaskDueState.dueToday);
      expect(task().dueState(companyDate: '2026-09-23'), TaskDueState.notDue);
    });

    test('without flags or a company date, it cannot be judged', () {
      expect(task().dueState(), isNull);
    });

    test('no due date is none; a closed task is never overdue', () {
      expect(task(due: null).dueState(), TaskDueState.none);
      expect(
        task(status: 'completed').dueState(companyDate: '2026-10-01'),
        TaskDueState.notDue,
      );
      expect(
        task(status: 'cancelled').dueState(companyDate: '2026-09-24'),
        TaskDueState.notDue,
      );
    });
  });

  group('withServerUpdate', () {
    final open = TaskItem.fromJson(
      myTaskJson('T1', dueDate: '2026-09-01', isOverdue: true),
    );

    test(
      'keeps the flags while the task stays open with the same due date',
      () {
        final updated = open.withServerUpdate(
          TaskItem.fromJson(
            taskJson(
              publicId: 'T1',
              status: 'in_progress',
              dueDate: '2026-09-01',
            ),
          ),
        );

        expect(updated.status, TaskStatus.inProgress);
        expect(updated.isOverdue, isTrue);
        expect(updated.isDueToday, isFalse);
      },
    );

    test('a closed task has neither flag', () {
      final updated = open.withServerUpdate(
        TaskItem.fromJson(
          taskJson(publicId: 'T1', status: 'completed', dueDate: '2026-09-01'),
        ),
      );

      expect(updated.isOverdue, isFalse);
      expect(updated.isDueToday, isFalse);
    });

    test('reopening a closed task drops the stale flags', () {
      final closed = TaskItem.fromJson(
        myTaskJson('T1', status: 'completed', dueDate: '2026-09-01'),
      );
      final reopened = closed.withServerUpdate(
        TaskItem.fromJson(
          taskJson(publicId: 'T1', status: 'todo', dueDate: '2026-09-01'),
        ),
      );

      expect(reopened.isOverdue, isNull);
      expect(
        reopened.dueState(companyDate: '2026-09-24'),
        TaskDueState.overdue,
      );
    });

    test('a changed due date drops the flags', () {
      final updated = open.withServerUpdate(
        TaskItem.fromJson(taskJson(publicId: 'T1', dueDate: '2026-12-01')),
      );

      expect(updated.isOverdue, isNull);
    });
  });

  group('MyTasksPage.fromJson', () {
    test('parses the company day, tasks and paging', () {
      final page = MyTasksPage.fromJson(
        myTasksBody(
          [myTaskJson('T1'), myTaskJson('T2')],
          page: 1,
          lastPage: 3,
          total: 60,
        ),
      );

      expect(page.companyDay.date, '2026-09-24');
      expect(page.companyDay.timezone, 'Asia/Manila');
      expect(page.tasks!.map((t) => t.publicId), ['T1', 'T2']);
      expect(page.hasProfile, isTrue);
      expect(page.hasMore, isTrue);
      expect(page.total, 60);
    });

    test('tasks: null is the no-profile state, distinct from empty', () {
      final none = MyTasksPage.fromJson(myTasksBody(null));
      final empty = MyTasksPage.fromJson(myTasksBody([]));

      expect(none.hasProfile, isFalse);
      expect(none.tasks, isNull);
      expect(none.hasMore, isFalse);
      expect(empty.hasProfile, isTrue);
      expect(empty.tasks, isEmpty);
    });

    test('a malformed body is a FormatException', () {
      expect(
        () => MyTasksPage.fromJson({
          'data': {'tasks': []},
        }),
        throwsFormatException,
      );
      expect(
        () => MyTasksPage.fromJson(myTasksBody([], date: 'today')),
        throwsFormatException,
      );
      expect(
        () => MyTasksPage.fromJson(myTasksBody([])..['meta'] = null),
        throwsFormatException,
      );
    });
  });
}

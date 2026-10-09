/// Typed view of the Phase 11 `TaskResource` — `GET /api/v1/tasks/{id}`,
/// `PATCH /api/v1/tasks/{id}`, and each item of `GET /api/v1/me/tasks`
/// (which adds `is_overdue`/`is_due_today`) — Phase 29A,
/// docs/phases/V1_PHASE_29_DEFINITION.md §5.1/§5.3.
///
/// Parsing is strict: a required field that is missing or of the wrong
/// type, or an unknown status/priority, throws a [FormatException]; a
/// nullable field stays null — never replaced with a placeholder.
library;

/// A task's status (Phase 11 `TaskStatus`).
enum TaskStatus {
  todo('todo', 'To do'),
  inProgress('in_progress', 'In progress'),
  blocked('blocked', 'Blocked'),
  completed('completed', 'Completed'),
  cancelled('cancelled', 'Cancelled');

  const TaskStatus(this.wireValue, this.label);

  /// The API's value.
  final String wireValue;

  /// The on-screen name.
  final String label;

  /// Completed and cancelled tasks are "closed" — `/me/tasks?state=closed`,
  /// and never overdue or due today (the server's `OverdueTasks` set).
  bool get isClosed =>
      this == TaskStatus.completed || this == TaskStatus.cancelled;

  /// The statuses the app offers (R-4): never Cancelled, which stays an
  /// Administrator/Project Lead action in the Admin Backoffice.
  static const selectable = [
    TaskStatus.todo,
    TaskStatus.inProgress,
    TaskStatus.blocked,
    TaskStatus.completed,
  ];

  static TaskStatus fromWire(Object? value) => TaskStatus.values.firstWhere(
    (s) => s.wireValue == value,
    orElse: () => throw FormatException('Unknown task status: $value'),
  );
}

/// A task's priority (Phase 11 `TaskPriority`).
enum TaskPriority {
  low('low', 'Low'),
  normal('normal', 'Normal'),
  high('high', 'High'),
  urgent('urgent', 'Urgent');

  const TaskPriority(this.wireValue, this.label);

  final String wireValue;
  final String label;

  static TaskPriority fromWire(Object? value) => TaskPriority.values.firstWhere(
    (p) => p.wireValue == value,
    orElse: () => throw FormatException('Unknown task priority: $value'),
  );
}

/// Where a task's due date falls relative to the company day.
enum TaskDueState {
  /// No due date.
  none,

  /// A due date that is neither today nor past — or any due date of a
  /// closed task, which is never overdue or due today.
  notDue,
  dueToday,
  overdue,
}

class TaskItem {
  const TaskItem({
    required this.publicId,
    required this.title,
    required this.status,
    required this.priority,
    this.description,
    this.dueDate,
    this.completedAt,
    this.project,
    this.assignee,
    this.createdBy,
    this.createdAt,
    this.updatedAt,
    this.isOverdue,
    this.isDueToday,
  });

  factory TaskItem.fromJson(Map<String, dynamic> json) {
    try {
      final dueDate = json['due_date'] as String?;
      if (dueDate != null && !_isDate(dueDate)) {
        throw FormatException('Invalid due_date: $dueDate');
      }

      return TaskItem(
        publicId: json['public_id'] as String,
        title: json['title'] as String,
        description: json['description'] as String?,
        status: TaskStatus.fromWire(json['status']),
        priority: TaskPriority.fromWire(json['priority']),
        dueDate: dueDate,
        completedAt: _instant(json['completed_at']),
        project: TaskRef.fromNullable(json['project'], nameKey: 'name'),
        assignee: TaskRef.fromNullable(json['assignee']),
        createdBy: TaskRef.fromNullable(json['created_by']),
        createdAt: _instant(json['created_at']),
        updatedAt: _instant(json['updated_at']),
        isOverdue: json['is_overdue'] as bool?,
        isDueToday: json['is_due_today'] as bool?,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected task shape: $e');
    }
  }

  final String publicId;
  final String title;
  final String? description;
  final TaskStatus status;
  final TaskPriority priority;

  /// `YYYY-MM-DD` — a calendar date with no time zone, as the API returns
  /// it.
  final String? dueDate;
  final DateTime? completedAt;
  final TaskRef? project;
  final TaskRef? assignee;
  final TaskRef? createdBy;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  /// The server's flags — present only on `/me/tasks` items (spec §5.1).
  final bool? isOverdue;
  final bool? isDueToday;

  /// The due state for display. Uses the server's flags when this item
  /// carries them; otherwise compares [dueDate] with [companyDate] — the
  /// company day (`YYYY-MM-DD`) the server reported — by exactly the
  /// server's rule (open only; before = overdue, equal = due today). Never
  /// the device clock. Null when neither is available and there is a due
  /// date to judge.
  TaskDueState? dueState({String? companyDate}) {
    final due = dueDate;
    if (due == null) {
      return TaskDueState.none;
    }
    if (status.isClosed) {
      return TaskDueState.notDue;
    }

    final overdue = isOverdue;
    final today = isDueToday;
    if (overdue != null && today != null) {
      return overdue
          ? TaskDueState.overdue
          : today
          ? TaskDueState.dueToday
          : TaskDueState.notDue;
    }

    if (companyDate == null) {
      return null;
    }
    final cmp = due.compareTo(companyDate);

    return cmp < 0
        ? TaskDueState.overdue
        : cmp == 0
        ? TaskDueState.dueToday
        : TaskDueState.notDue;
  }

  /// The server's latest version of this task ([updated], typically a
  /// `PATCH` response, which carries no flags). This item's server flags
  /// are kept only while they still hold — the task was and still is open
  /// with the same due date; a closed task has neither flag; otherwise they
  /// are dropped and [dueState] falls back to the company date.
  TaskItem withServerUpdate(TaskItem updated) {
    final bool? overdue;
    final bool? dueToday;
    if (updated.isOverdue != null && updated.isDueToday != null) {
      overdue = updated.isOverdue;
      dueToday = updated.isDueToday;
    } else if (updated.status.isClosed) {
      overdue = false;
      dueToday = false;
    } else if (!status.isClosed && updated.dueDate == dueDate) {
      overdue = isOverdue;
      dueToday = isDueToday;
    } else {
      overdue = null;
      dueToday = null;
    }

    return TaskItem(
      publicId: updated.publicId,
      title: updated.title,
      description: updated.description,
      status: updated.status,
      priority: updated.priority,
      dueDate: updated.dueDate,
      completedAt: updated.completedAt,
      project: updated.project,
      assignee: updated.assignee,
      createdBy: updated.createdBy,
      createdAt: updated.createdAt,
      updatedAt: updated.updatedAt,
      isOverdue: overdue,
      isDueToday: dueToday,
    );
  }
}

/// A `{public_id, <name>}` reference: a project (`name`) or a person
/// (`display_name`, with `employee_number`).
class TaskRef {
  const TaskRef({required this.publicId, required this.name});

  /// Null for a JSON null or an absent key; otherwise strictly parsed.
  static TaskRef? fromNullable(
    Object? json, {
    String nameKey = 'display_name',
  }) {
    if (json == null) {
      return null;
    }

    final map = json as Map<String, dynamic>;

    return TaskRef(
      publicId: map['public_id'] as String,
      name: map[nameKey] as String,
    );
  }

  final String publicId;
  final String name;
}

/// The company day `/me/tasks` judged its flags against.
class TaskCompanyDay {
  const TaskCompanyDay({required this.date, required this.timezone});

  factory TaskCompanyDay.fromJson(Map<String, dynamic> json) {
    final date = json['date'] as String;
    if (!_isDate(date)) {
      throw FormatException('Invalid company_day.date: $date');
    }

    return TaskCompanyDay(date: date, timezone: json['timezone'] as String);
  }

  /// `YYYY-MM-DD` in the company timezone.
  final String date;
  final String timezone;
}

/// One page of `GET /api/v1/me/tasks`.
class MyTasksPage {
  const MyTasksPage({
    required this.companyDay,
    required this.tasks,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  /// Parses the whole response body. `tasks` is null — and `meta` is
  /// null — when no Staff record is linked to the account.
  factory MyTasksPage.fromJson(Map<String, dynamic> json) {
    try {
      final data = json['data'] as Map<String, dynamic>;
      final companyDay = TaskCompanyDay.fromJson(
        data['company_day'] as Map<String, dynamic>,
      );
      final tasks = data['tasks'] as List<dynamic>?;

      if (tasks == null) {
        return MyTasksPage(
          companyDay: companyDay,
          tasks: null,
          currentPage: 1,
          lastPage: 1,
          total: 0,
        );
      }

      final meta = json['meta'] as Map<String, dynamic>;

      return MyTasksPage(
        companyDay: companyDay,
        tasks: tasks
            .map((t) => TaskItem.fromJson(t as Map<String, dynamic>))
            .toList(growable: false),
        currentPage: meta['current_page'] as int,
        lastPage: meta['last_page'] as int,
        total: meta['total'] as int,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected /me/tasks response shape: $e');
    }
  }

  final TaskCompanyDay companyDay;

  /// Null when the account has no linked Staff record (the no-profile
  /// state) — distinct from an empty list.
  final List<TaskItem>? tasks;
  final int currentPage;
  final int lastPage;
  final int total;

  bool get hasProfile => tasks != null;
  bool get hasMore => currentPage < lastPage;
}

final _datePattern = RegExp(r'^\d{4}-\d{2}-\d{2}$');

bool _isDate(String value) => _datePattern.hasMatch(value);

DateTime? _instant(Object? value) =>
    value == null ? null : DateTime.parse(value as String).toUtc();

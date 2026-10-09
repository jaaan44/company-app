import 'package:flutter/foundation.dart';

import 'package:mobile/features/tasks/domain/task_item.dart';

/// One confirmed change to a task made in this app session.
class TaskChange {
  const TaskChange.updated(TaskItem this.task) : publicId = '';

  const TaskChange.removed(this.publicId) : task = null;

  /// The server's updated task, or null when the task is no longer mine
  /// (a `403` showed it was reassigned or is no longer visible).
  final TaskItem? task;
  final String publicId;

  /// The task's public id in either case.
  String get id => task?.publicId ?? publicId;
}

/// App-session-wide record of confirmed task changes (Phase 29A, spec §5.3
/// "After a change"). The detail screen records each confirmed save here;
/// the task lists apply it at once (a task that moved between Open and
/// Done leaves its segment) and, like Home, treat themselves as stale so
/// they refresh the next time they're shown. Only confirmed server results
/// are recorded — never an optimistic guess (R-7).
class TaskChanges extends ChangeNotifier {
  int _revision = 0;
  TaskChange? _last;

  /// Increases with every recorded change. A screen compares it with the
  /// revision it last loaded to know whether it is stale.
  int get revision => _revision;

  /// The most recent change, or null before any.
  TaskChange? get last => _last;

  void record(TaskChange change) {
    _revision++;
    _last = change;
    notifyListeners();
  }
}

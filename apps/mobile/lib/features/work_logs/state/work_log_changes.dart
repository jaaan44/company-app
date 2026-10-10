import 'package:flutter/foundation.dart';

import 'package:mobile/features/work_logs/domain/work_log.dart';

/// One confirmed change to one of my work logs.
class WorkLogChange {
  const WorkLogChange.saved(WorkLog this.log) : publicId = '';

  const WorkLogChange.deleted(this.publicId) : log = null;

  /// The server's saved log (created or updated), or null when deleted.
  final WorkLog? log;
  final String publicId;

  String get id => log?.publicId ?? publicId;
  bool get isDeleted => log == null;
}

/// App-session-wide record of confirmed work-log saves and deletions
/// (Phase 29B, spec §6.4) — the `TaskChanges` pattern from 29A. A form
/// opened from anywhere (the list, or a task's detail) records here; the
/// list applies the change and refreshes. Only confirmed server results are
/// recorded, never a guess.
class WorkLogChanges extends ChangeNotifier {
  int _revision = 0;
  WorkLogChange? _last;

  int get revision => _revision;
  WorkLogChange? get last => _last;

  void record(WorkLogChange change) {
    _revision++;
    _last = change;
    notifyListeners();
  }
}

import 'package:flutter/foundation.dart';

/// App-session-wide record of confirmed report changes (Phase 33, R-11):
/// a save, submit, delete, return to draft or attachment change. Both
/// lists reload — the `TaskChanges` pattern.
class ReportChanges extends ChangeNotifier {
  int _revision = 0;

  int get revision => _revision;

  void record() {
    _revision++;
    notifyListeners();
  }
}

import 'package:flutter/foundation.dart';

import 'package:mobile/features/leave/domain/leave.dart';

/// App-session-wide record of confirmed leave changes — a submitted,
/// cancelled, approved or rejected request (Phase 31, R-11) — the
/// `TaskChanges` pattern. The Leave screens and Schedule (approved leave
/// shows there) reload when next shown. Only confirmed server results are
/// recorded, never a guess.
class LeaveChanges extends ChangeNotifier {
  int _revision = 0;
  LeaveRequest? _last;

  int get revision => _revision;

  /// The server's request after the latest change.
  LeaveRequest? get last => _last;

  void record(LeaveRequest request) {
    _revision++;
    _last = request;
    notifyListeners();
  }
}

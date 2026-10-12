import 'package:flutter/foundation.dart';

/// App-session-wide record of confirmed communication changes (Phase 32,
/// R-11): a message sent, a conversation read, a notification read, all
/// read, an announcement acknowledged. Home, Messages, Notifications and
/// Announcements reload when it changes — the `TaskChanges` pattern.
class InboxChanges extends ChangeNotifier {
  int _revision = 0;

  int get revision => _revision;

  void record() {
    _revision++;
    notifyListeners();
  }
}

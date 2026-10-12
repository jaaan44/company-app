import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/notifications/data/notifications_api_client.dart';
import 'package:mobile/features/notifications/domain/app_notification.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// The Notifications inbox (Phase 32, R-6): newest first, 20 a page; mark
/// one or all read, confirmed by the server. Works without a profile.
class NotificationsController extends PagedSearchController<AppNotification> {
  NotificationsController(this._client, {this.changes});

  final NotificationsApiClient _client;
  final InboxChanges? changes;
  ScheduleCompanyDay? _companyDay;
  bool _isMarkingAll = false;
  String? _actionMessage;
  bool _closed = false;

  ScheduleCompanyDay? get companyDay => _companyDay;
  bool get isMarkingAll => _isMarkingAll;

  /// The last failed mark-all, safe to show.
  String? get actionMessage => _actionMessage;

  bool get hasUnread => items.any((n) => n.isUnread);

  @override
  Future<PagedResult<AppNotification>?> fetchPage({
    required String query,
    required int page,
  }) async {
    final result = await _client.fetchPage(page: page);
    _companyDay = result.companyDay;

    return result.page;
  }

  /// Marks [n] read (if unread). Returns true when it is read now. A
  /// failure is quiet: opening its source still goes ahead (R-6).
  Future<bool> markRead(AppNotification n) async {
    if (!n.isUnread) {
      return true;
    }
    try {
      replaceItem(await _client.markRead(n.publicId));
      changes?.record();

      return true;
    } on ApiException {
      return false;
    }
  }

  /// Marks everything read, then reloads the list from the server.
  Future<bool> markAllRead() async {
    if (_isMarkingAll) {
      return false;
    }
    _isMarkingAll = true;
    _actionMessage = null;
    _changed();
    try {
      await _client.markAllRead();
      changes?.record();
      await refresh();

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiException catch (e) {
      _actionMessage = e is ApiNetworkException
          ? "Couldn't mark all read. Check your connection."
          : "Couldn't mark all read. Please try again.";

      return false;
    } finally {
      _isMarkingAll = false;
      _changed();
    }
  }

  void _changed() {
    if (!_closed) {
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _closed = true;
    super.dispose();
  }

  @override
  String idOf(AppNotification item) => item.publicId;

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load notifications. Check your connection.",
    _ => 'Something went wrong loading notifications.',
  };
}

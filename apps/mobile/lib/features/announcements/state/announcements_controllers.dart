import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/announcements/data/announcements_api_client.dart';
import 'package:mobile/features/announcements/domain/announcement.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// The Announcements feed (Phase 32, R-7): newest first, 20 a page, never
/// searched. A `403` is the no-profile state (R-9).
class AnnouncementsController extends PagedSearchController<Announcement> {
  AnnouncementsController(this._client);

  final AnnouncementsApiClient _client;
  ScheduleCompanyDay? _companyDay;

  ScheduleCompanyDay? get companyDay => _companyDay;

  @override
  Future<PagedResult<Announcement>?> fetchPage({
    required String query,
    required int page,
  }) async {
    try {
      final result = await _client.fetchPage(page: page);
      _companyDay = result.companyDay;

      return result.page;
    } on ApiForbiddenException {
      return null;
    }
  }

  @override
  String idOf(Announcement item) => item.publicId;

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load announcements. Check your connection.",
    _ => 'Something went wrong loading announcements.',
  };
}

enum AnnouncementStatusView { loading, loaded, noProfile, error }

/// One announcement (R-7): loaded (quietly when the list gave it), and
/// acknowledged once, confirmed by the server.
class AnnouncementController extends ChangeNotifier {
  AnnouncementController(
    this._client,
    this.publicId, {
    Announcement? initial,
    this.changes,
  }) : _data = initial,
       _status = initial == null
           ? AnnouncementStatusView.loading
           : AnnouncementStatusView.loaded;

  final AnnouncementsApiClient _client;
  final String publicId;
  final InboxChanges? changes;

  AnnouncementStatusView _status;
  Announcement? _data;
  String? _errorMessage;
  String? _actionMessage;
  bool _isAcknowledging = false;
  bool _disposed = false;

  AnnouncementStatusView get status => _status;
  Announcement? get data => _data;
  String? get errorMessage => _errorMessage;
  String? get actionMessage => _actionMessage;
  bool get isAcknowledging => _isAcknowledging;

  Future<void> load() async {
    if (_data == null) {
      _status = AnnouncementStatusView.loading;
      _errorMessage = null;
      _notify();
    }
    try {
      _data = await _client.fetch(publicId);
      _status = AnnouncementStatusView.loaded;
    } on ApiSessionExpiredException {
      // The app is returning to Login.
    } on ApiForbiddenException {
      if (_data == null) {
        _status = AnnouncementStatusView.noProfile;
      }
    } on ApiException catch (e) {
      if (_data == null) {
        _status = AnnouncementStatusView.error;
        _errorMessage = switch (e) {
          ApiNetworkException() =>
            "Couldn't load this announcement. Check your connection.",
          ApiRequestException(statusCode: 404) =>
            'This announcement is no longer available.',
          _ => 'Something went wrong loading this announcement.',
        };
      }
    } finally {
      _notify();
    }
  }

  /// Sends once; the screen changes only when the server confirms.
  Future<bool> acknowledge() async {
    final current = _data;
    if (_isAcknowledging || current == null || current.isAcknowledged) {
      return false;
    }
    _isAcknowledging = true;
    _actionMessage = null;
    _notify();

    try {
      _data = await _client.acknowledge(publicId);
      changes?.record();

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiException catch (e) {
      _actionMessage = switch (e) {
        ApiNetworkException() =>
          "Couldn't acknowledge. Check your connection and try again.",
        ApiRequestException(statusCode: 404) =>
          'This announcement is no longer available.',
        _ => "Couldn't acknowledge. Please try again.",
      };

      return false;
    } finally {
      _isAcknowledging = false;
      _notify();
    }
  }

  void _notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}

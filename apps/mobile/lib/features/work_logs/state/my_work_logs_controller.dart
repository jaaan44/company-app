import 'dart:async';

import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/work_logs/data/work_logs_api_client.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';
import 'package:mobile/features/work_logs/state/work_log_changes.dart';

enum MyWorkLogsStatus { loading, loaded, noProfile, error }

/// State for My work logs (Phase 29B, spec §6.4–§6.5): `GET /me/work-logs`
/// in server order (newest date first), grouped by work date across pages,
/// with "load more" paging and pull-to-refresh — the Phase 28/29A list
/// rules (one first-page request at a time; responses from an older
/// generation are dropped; no duplicates).
///
/// **No profile (R-13):** the API answers `403` without a linked Staff
/// record. `ApiClient` has already re-checked the session with `/auth/me`
/// by then, so a `403` that reaches this controller can only mean that —
/// it becomes [MyWorkLogsStatus.noProfile]. No message text is parsed.
///
/// **Changes:** a confirmed deletion leaves the list at once and an edit
/// replaces its row; any change marks the list [isStale] so the page
/// refreshes it ([refreshIfStale]) — a new log, or one whose date moved,
/// then appears where the server puts it.
class MyWorkLogsController extends ChangeNotifier {
  MyWorkLogsController(this._client, {this.changes}) {
    _seenRevision = changes?.revision ?? 0;
    changes?.addListener(_onChange);
  }

  final WorkLogsApiClient _client;
  final WorkLogChanges? changes;

  MyWorkLogsStatus _status = MyWorkLogsStatus.loading;
  final List<WorkLog> _items = [];
  final Set<String> _ids = {};
  WorkLogCompanyDay? _companyDay;
  String? _errorMessage;
  int _nextPage = 1;
  bool _hasMore = false;
  bool _isRefreshing = false;
  bool _isLoadingMore = false;
  bool _loadMoreFailed = false;
  int _seenRevision = 0;

  int _generation = 0;
  Future<bool>? _firstPageInFlight;
  bool _disposed = false;

  MyWorkLogsStatus get status => _status;

  /// The loaded logs, in server order, without duplicates.
  List<WorkLog> get items => List.unmodifiable(_items);

  /// [items] grouped by work date, newest date first. The server's order
  /// keeps one date's logs together, so a date that spans two pages
  /// becomes one group once both are loaded.
  List<WorkLogDay> get days {
    final days = <WorkLogDay>[];
    for (final log in _items) {
      if (days.isEmpty || days.last.date != log.workDate) {
        days.add(WorkLogDay(date: log.workDate, logs: [log]));
      } else {
        days.last.logs.add(log);
      }
    }

    return days;
  }

  /// The company day the server reported (null until the first load).
  WorkLogCompanyDay? get companyDay => _companyDay;
  String? get errorMessage => _errorMessage;
  bool get hasMore => _hasMore;
  bool get isRefreshing => _isRefreshing;
  bool get isLoadingMore => _isLoadingMore;
  bool get loadMoreFailed => _loadMoreFailed;

  /// True when a change was recorded after this list was last loaded.
  bool get isStale => changes != null && _seenRevision != changes!.revision;

  Future<void> load() async {
    final inFlight = _firstPageInFlight;
    if (inFlight != null) {
      await inFlight;

      return;
    }

    _startGeneration(clearItems: true);
    await _fetchFirstPage();
  }

  /// Pull-to-refresh: keeps the current list on screen. Returns false when
  /// the refresh failed but the earlier list is still shown.
  Future<bool> refresh() {
    final inFlight = _firstPageInFlight;
    if (inFlight != null) {
      return inFlight;
    }

    _startGeneration(clearItems: false);
    _isRefreshing = true;
    _notify();

    return _fetchFirstPage();
  }

  Future<void> refreshIfStale() async {
    if (!isStale) {
      return;
    }
    if (_status == MyWorkLogsStatus.loaded) {
      await refresh();
    } else {
      await load();
    }
  }

  Future<void> loadMore() async {
    if (!_hasMore ||
        _isLoadingMore ||
        _status != MyWorkLogsStatus.loaded ||
        _firstPageInFlight != null) {
      return;
    }

    final generation = _generation;
    _isLoadingMore = true;
    _loadMoreFailed = false;
    _notify();

    try {
      final page = await _client.fetchMyWorkLogs(page: _nextPage);
      if (generation != _generation) {
        return;
      }
      _companyDay = page.companyDay;
      _append(page);
    } on ApiSessionExpiredException {
      // The session has already ended and the app is returning to Login.
    } on ApiException {
      if (generation == _generation) {
        _loadMoreFailed = true;
      }
    } finally {
      if (generation == _generation) {
        _isLoadingMore = false;
        _notify();
      }
    }
  }

  void _onChange() {
    final change = changes!.last;
    if (change == null) {
      return;
    }

    final index = _items.indexWhere((l) => l.publicId == change.id);
    if (index >= 0) {
      final saved = change.log;
      if (saved == null) {
        _ids.remove(change.id);
        _items.removeAt(index);
      } else if (saved.workDate == _items[index].workDate) {
        _items[index] = saved;
      }
      // A changed date waits for the refresh to place it correctly.
    }

    _notify();
  }

  void _startGeneration({required bool clearItems}) {
    _generation++;
    _isLoadingMore = false;
    _loadMoreFailed = false;

    if (clearItems) {
      _items.clear();
      _ids.clear();
      _hasMore = false;
      _status = MyWorkLogsStatus.loading;
      _errorMessage = null;
      _notify();
    }
  }

  Future<bool> _fetchFirstPage() {
    final request = _performFirstPage(_generation);
    _firstPageInFlight = request;

    return request.whenComplete(() {
      if (identical(_firstPageInFlight, request)) {
        _firstPageInFlight = null;
      }
    });
  }

  Future<bool> _performFirstPage(int generation) async {
    final revision = changes?.revision ?? 0;

    try {
      final page = await _client.fetchMyWorkLogs();
      if (generation != _generation) {
        return true;
      }

      _items.clear();
      _ids.clear();
      _companyDay = page.companyDay;
      _seenRevision = revision;
      _append(page);
      _status = MyWorkLogsStatus.loaded;
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      return true;
    } on ApiForbiddenException {
      if (generation != _generation) {
        return true;
      }
      // R-13: the only 403 left after the session re-check.
      _items.clear();
      _ids.clear();
      _hasMore = false;
      _seenRevision = revision;
      _status = MyWorkLogsStatus.noProfile;

      return true;
    } on ApiException catch (e) {
      if (generation != _generation) {
        return true;
      }

      if (_status == MyWorkLogsStatus.loaded) {
        return false;
      }

      _status = MyWorkLogsStatus.error;
      _errorMessage = switch (e) {
        ApiNetworkException() =>
          "Couldn't load your work logs. Check your connection.",
        _ => 'Something went wrong loading your work logs.',
      };

      return true;
    } finally {
      if (generation == _generation) {
        _isRefreshing = false;
        _notify();
      }
    }
  }

  void _append(MyWorkLogsPage page) {
    for (final log in page.items) {
      if (_ids.add(log.publicId)) {
        _items.add(log);
      }
    }

    _hasMore = page.hasMore;
    _nextPage = page.currentPage + 1;
  }

  void _notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    changes?.removeListener(_onChange);
    super.dispose();
  }
}

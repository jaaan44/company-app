import 'dart:async';

import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/domain/task_item.dart';
import 'package:mobile/features/tasks/state/task_changes.dart';

enum MyTasksStatus { loading, loaded, noProfile, error }

/// State for one segment of the Tasks list — Open or Done (Phase 29A, spec
/// §5.3): `GET /me/tasks` in server order, "load more" paging,
/// pull-to-refresh, and the no-profile state.
///
/// The Phase 28 directory rules apply: one first-page request at a time,
/// and a response from an older generation (a "load more" overtaken by a
/// refresh) is dropped, so the list never mixes two snapshots.
///
/// **Task changes.** When [changes] records a confirmed save, the change is
/// applied at once: a task that now belongs to the other segment leaves
/// this one, and one that stays is replaced in place. Any change also marks
/// the list [isStale], so the page refreshes it the next time it is shown
/// ([refreshIfStale]) — a task that moved *into* this segment, or one
/// reordered by its new state, then appears where the server puts it.
///
/// Owned by its page for the page's lifetime (DEC-025). Session expiry is
/// ignored here, as in every Phase 27/28 controller: `ApiClient` has
/// already ended the session by the time an [ApiSessionExpiredException]
/// arrives.
class MyTasksController extends ChangeNotifier {
  MyTasksController(this._client, {required this.state, this.changes}) {
    _seenRevision = changes?.revision ?? 0;
    changes?.addListener(_onTaskChange);
  }

  final TasksApiClient _client;
  final TaskListState state;
  final TaskChanges? changes;

  MyTasksStatus _status = MyTasksStatus.loading;
  final List<TaskItem> _items = [];
  final Set<String> _ids = {};
  TaskCompanyDay? _companyDay;
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

  MyTasksStatus get status => _status;

  /// The loaded tasks, in server order, without duplicates.
  List<TaskItem> get items => List.unmodifiable(_items);

  /// The company day the server judged the flags against (null until the
  /// first successful load).
  TaskCompanyDay? get companyDay => _companyDay;

  /// Set when [status] is [MyTasksStatus.error]: a message safe to show.
  String? get errorMessage => _errorMessage;
  bool get hasMore => _hasMore;
  bool get isRefreshing => _isRefreshing;
  bool get isLoadingMore => _isLoadingMore;

  /// True after a "load more" failed; the list is kept and [loadMore]
  /// retries (the Phase 28 retry row).
  bool get loadMoreFailed => _loadMoreFailed;

  /// True when a task change was recorded after this list was last loaded.
  bool get isStale => changes != null && _seenRevision != changes!.revision;

  /// Initial load and "Try again": the first page with the full loading
  /// state. Joins a first-page request in flight.
  Future<void> load() async {
    final inFlight = _firstPageInFlight;
    if (inFlight != null) {
      await inFlight;

      return;
    }

    _startGeneration(clearItems: true);
    await _fetchFirstPage();
  }

  /// Pull-to-refresh: reloads the first page while keeping the current
  /// list on screen. Returns false when the refresh failed but the earlier
  /// list is still shown; true otherwise.
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

  /// Refreshes only when [isStale]; called when the list is shown again.
  Future<void> refreshIfStale() async {
    if (!isStale) {
      return;
    }
    if (_status == MyTasksStatus.loaded || _status == MyTasksStatus.noProfile) {
      await refresh();
    } else {
      await load();
    }
  }

  /// Fetches the next page, appending it. Does nothing when there is no
  /// next page, the list isn't loaded, or a request is already running.
  Future<void> loadMore() async {
    if (!_hasMore ||
        _isLoadingMore ||
        _status != MyTasksStatus.loaded ||
        _firstPageInFlight != null) {
      return;
    }

    final generation = _generation;
    _isLoadingMore = true;
    _loadMoreFailed = false;
    _notify();

    try {
      final page = await _client.fetchMyTasks(state: state, page: _nextPage);
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

  void _onTaskChange() {
    final change = changes!.last;
    if (change == null) {
      return;
    }

    final index = _items.indexWhere((t) => t.publicId == change.id);
    if (index >= 0) {
      final updated = change.task;
      if (updated == null || _belongsElsewhere(updated)) {
        _ids.remove(change.id);
        _items.removeAt(index);
      } else {
        _items[index] = _items[index].withServerUpdate(updated);
      }
    }

    // Always stale: the server decides where a changed task now sits.
    _notify();
  }

  bool _belongsElsewhere(TaskItem task) =>
      task.status.isClosed != (state == TaskListState.closed);

  void _startGeneration({required bool clearItems}) {
    _generation++;
    _isLoadingMore = false;
    _loadMoreFailed = false;

    if (clearItems) {
      _items.clear();
      _ids.clear();
      _hasMore = false;
      _status = MyTasksStatus.loading;
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
      final page = await _client.fetchMyTasks(state: state);
      if (generation != _generation) {
        return true;
      }

      _items.clear();
      _ids.clear();
      _companyDay = page.companyDay;
      _seenRevision = revision;

      if (!page.hasProfile) {
        _hasMore = false;
        _status = MyTasksStatus.noProfile;
      } else {
        _append(page);
        _status = MyTasksStatus.loaded;
      }
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      return true;
    } on ApiException catch (e) {
      if (generation != _generation) {
        return true;
      }

      if (_status == MyTasksStatus.loaded ||
          _status == MyTasksStatus.noProfile) {
        // Refresh failed: keep showing the earlier list.
        return false;
      }

      _status = MyTasksStatus.error;
      _errorMessage = _messageFor(e);

      return true;
    } finally {
      if (generation == _generation) {
        _isRefreshing = false;
        _notify();
      }
    }
  }

  void _append(MyTasksPage page) {
    for (final task in page.tasks ?? const <TaskItem>[]) {
      // Defensive: the server's order is total (id tie-breaker), but never
      // show the same task twice.
      if (_ids.add(task.publicId)) {
        _items.add(task);
      }
    }

    _hasMore = page.hasMore;
    _nextPage = page.currentPage + 1;
  }

  String _messageFor(ApiException e) => switch (e) {
    ApiNetworkException() => "Couldn't load your tasks. Check your connection.",
    ApiForbiddenException() => "You don't have access to your tasks.",
    _ => 'Something went wrong loading your tasks.',
  };

  void _notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    changes?.removeListener(_onTaskChange);
    super.dispose();
  }
}

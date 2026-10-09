import 'dart:async';

import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/people/domain/staff_directory_page.dart';
import 'package:mobile/features/people/domain/staff_member.dart';

enum DirectoryStatus { loading, loaded, error }

/// State for the Staff directory list (Phase 28, spec §7–§8): active staff
/// only (R-1, enforced by [PeopleApiClient]), a debounced name search,
/// "load more" paging, and pull-to-refresh.
///
/// **Stale responses are dropped.** Every new search and every refresh
/// starts a new generation. A response that belongs to an older generation
/// (a slow first page for an earlier query, or a "load more" that was
/// overtaken by a refresh) is ignored, so the list never mixes two queries
/// or two snapshots.
///
/// Owned by its page for the page's lifetime (DEC-025). Session expiry is
/// ignored here, as in every Phase 27/28 controller: `ApiClient` has already
/// ended the session by the time an [ApiSessionExpiredException] arrives.
class StaffDirectoryController extends ChangeNotifier {
  StaffDirectoryController(
    this._client, {
    this.debounce = const Duration(milliseconds: 300),
  });

  final PeopleApiClient _client;

  /// How long [setQuery] waits for typing to pause before searching.
  final Duration debounce;

  DirectoryStatus _status = DirectoryStatus.loading;
  final List<StaffMember> _items = [];
  final Set<String> _ids = {};
  String _query = '';
  String? _errorMessage;
  int _nextPage = 1;
  bool _hasMore = false;
  bool _isRefreshing = false;
  bool _isLoadingMore = false;
  bool _loadMoreFailed = false;

  int _generation = 0;
  Future<bool>? _firstPageInFlight;
  Timer? _debounceTimer;
  bool _disposed = false;

  DirectoryStatus get status => _status;

  /// The loaded staff, in server order (last name, first name), without
  /// duplicates.
  List<StaffMember> get items => List.unmodifiable(_items);

  /// The search the current [items] belong to (trimmed; empty = everyone).
  String get query => _query;

  /// Set when [status] is [DirectoryStatus.error]: a message safe to show.
  String? get errorMessage => _errorMessage;
  bool get hasMore => _hasMore;
  bool get isRefreshing => _isRefreshing;
  bool get isLoadingMore => _isLoadingMore;

  /// True after a "load more" failed; the list is kept and [loadMore]
  /// retries (spec §8: "Couldn't load more. Tap to retry.").
  bool get loadMoreFailed => _loadMoreFailed;

  /// Initial load and "Try again": the first page for the current query,
  /// with the full loading state. Joins a first-page request in flight.
  Future<void> load() async {
    final inFlight = _firstPageInFlight;
    if (inFlight != null) {
      await inFlight;

      return;
    }

    _startGeneration(clearItems: true);
    await _fetchFirstPage();
  }

  /// Pull-to-refresh: reloads the first page for the current query while
  /// keeping the current list on screen. Returns false when the refresh
  /// failed but the earlier list is still shown; true otherwise.
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

  /// Search text changed. Waits [debounce] for typing to pause, then
  /// searches. Setting the query that is already shown does nothing.
  void setQuery(String text) {
    _debounceTimer?.cancel();
    final query = text.trim();

    if (query == _query && _status != DirectoryStatus.error) {
      return;
    }

    _debounceTimer = Timer(debounce, () {
      _query = query;
      _firstPageInFlight = null;
      unawaited(load());
    });
  }

  /// Fetches the next page, appending it. Does nothing when there is no
  /// next page, the list isn't loaded, or a "load more" is already running.
  Future<void> loadMore() async {
    if (!_hasMore ||
        _isLoadingMore ||
        _status != DirectoryStatus.loaded ||
        _firstPageInFlight != null) {
      return;
    }

    final generation = _generation;
    _isLoadingMore = true;
    _loadMoreFailed = false;
    _notify();

    try {
      final page = await _client.fetchDirectoryPage(
        query: _query,
        page: _nextPage,
      );
      if (generation != _generation) {
        return;
      }
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

  void _startGeneration({required bool clearItems}) {
    _generation++;
    _isLoadingMore = false;
    _loadMoreFailed = false;

    if (clearItems) {
      _items.clear();
      _ids.clear();
      _hasMore = false;
      _status = DirectoryStatus.loading;
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
    try {
      final page = await _client.fetchDirectoryPage(query: _query);
      if (generation != _generation) {
        return true;
      }

      _items.clear();
      _ids.clear();
      _append(page);
      _status = DirectoryStatus.loaded;
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      return true;
    } on ApiException catch (e) {
      if (generation != _generation) {
        return true;
      }

      if (_items.isNotEmpty) {
        // Refresh failed: keep showing the earlier list.
        return false;
      }

      _status = DirectoryStatus.error;
      _errorMessage = _messageFor(e);

      return true;
    } finally {
      if (generation == _generation) {
        _isRefreshing = false;
        _notify();
      }
    }
  }

  void _append(StaffDirectoryPage page) {
    for (final member in page.items) {
      // Defensive: the server's order is deterministic (R-6), but never
      // show the same person twice.
      if (_ids.add(member.publicId)) {
        _items.add(member);
      }
    }

    _hasMore = page.hasMore;
    _nextPage = page.currentPage + 1;
  }

  String _messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load the staff directory. Check your connection.",
    ApiForbiddenException() => "You don't have access to the staff directory.",
    _ => 'Something went wrong loading the staff directory.',
  };

  void _notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    _debounceTimer?.cancel();
    super.dispose();
  }
}

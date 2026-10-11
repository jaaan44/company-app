import 'dart:async';

import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';

enum PagedListStatus {
  loading,
  loaded,

  /// The list needs a linked Staff record and the account has none.
  noProfile,
  error,
}

/// A searchable, "load more" list — the Phase 28 Staff directory's
/// behaviour (`StaffDirectoryController`), made generic for the Projects
/// and Clients lists (Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md
/// §7.4):
///
/// - [load] (initial load and "Try again") shows the full loading state;
/// - [refresh] (pull-to-refresh) keeps the current list on screen;
/// - [setQuery] waits [debounce] for typing to pause, then searches;
/// - [loadMore] appends the next page; a failure keeps the list and sets
///   [loadMoreFailed] so the row can offer a retry.
///
/// **Stale responses are dropped.** Every new search and every refresh
/// starts a new generation; a response from an older one is ignored, so the
/// list never mixes two queries or two snapshots. Items are de-duplicated
/// by [idOf].
///
/// Session expiry is ignored, as in every Phase 27–29 controller:
/// `ApiClient` has already ended the session by the time an
/// [ApiSessionExpiredException] arrives.
abstract class PagedSearchController<T> extends ChangeNotifier {
  PagedSearchController({this.debounce = const Duration(milliseconds: 300)});

  /// How long [setQuery] waits for typing to pause before searching.
  final Duration debounce;

  PagedListStatus _status = PagedListStatus.loading;
  final List<T> _items = [];
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

  /// One page for [query] (trimmed; empty = no search). Returns null when
  /// the list isn't available to this account (no linked Staff record).
  @protected
  Future<PagedResult<T>?> fetchPage({required String query, required int page});

  /// A stable id for de-duplication.
  @protected
  String idOf(T item);

  /// The on-screen message for a failure with no list to fall back on.
  @protected
  String messageFor(ApiException e);

  PagedListStatus get status => _status;

  /// The loaded items, in server order, without duplicates.
  List<T> get items => List.unmodifiable(_items);

  /// The search the current [items] belong to (trimmed; empty = all).
  String get query => _query;

  /// Set when [status] is [PagedListStatus.error]: a message safe to show.
  String? get errorMessage => _errorMessage;
  bool get hasMore => _hasMore;
  bool get isRefreshing => _isRefreshing;
  bool get isLoadingMore => _isLoadingMore;
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

  /// Pull-to-refresh: reloads the first page while keeping the current list
  /// on screen. Returns false when the refresh failed but the earlier list
  /// is still shown; true otherwise.
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

  /// Search text changed. Setting the query already shown does nothing.
  void setQuery(String text) {
    _debounceTimer?.cancel();
    final query = text.trim();

    if (query == _query && _status != PagedListStatus.error) {
      return;
    }

    _debounceTimer = Timer(debounce, () {
      _query = query;
      _firstPageInFlight = null;
      unawaited(load());
    });
  }

  /// Fetches the next page, appending it. Does nothing when there is no
  /// next page, the list isn't loaded, or a request is already running.
  Future<void> loadMore() async {
    if (!_hasMore ||
        _isLoadingMore ||
        _status != PagedListStatus.loaded ||
        _firstPageInFlight != null) {
      return;
    }

    final generation = _generation;
    _isLoadingMore = true;
    _loadMoreFailed = false;
    _notify();

    try {
      final page = await fetchPage(query: _query, page: _nextPage);
      if (generation != _generation || page == null) {
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
      _status = PagedListStatus.loading;
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
      final page = await fetchPage(query: _query, page: 1);
      if (generation != _generation) {
        return true;
      }

      _items.clear();
      _ids.clear();
      if (page == null) {
        _hasMore = false;
        _status = PagedListStatus.noProfile;
      } else {
        _append(page);
        _status = PagedListStatus.loaded;
      }
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

      _status = PagedListStatus.error;
      _errorMessage = messageFor(e);

      return true;
    } finally {
      if (generation == _generation) {
        _isRefreshing = false;
        _notify();
      }
    }
  }

  void _append(PagedResult<T> page) {
    for (final item in page.items) {
      // Defensive: the server's order is deterministic (R-19), but never
      // show the same record twice.
      if (_ids.add(idOf(item))) {
        _items.add(item);
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
    _debounceTimer?.cancel();
    super.dispose();
  }
}

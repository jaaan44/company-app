import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/home/data/home_api_client.dart';
import 'package:mobile/features/home/domain/home_summary.dart';

enum HomeStatus { loading, loaded, error }

/// State for the employee Home screen (Phase 27). A plain [ChangeNotifier]
/// (DEC-025) owned by `HomePage` for the page's lifetime — a new
/// authenticated session builds a new shell, page and controller, so no
/// previous session's data can survive a logout or expiry.
///
/// Session expiry is not handled here: `ApiClient` has already ended the
/// session (and the router has left the shell) by the time an
/// [ApiSessionExpiredException] arrives, so it is simply ignored.
class HomeController extends ChangeNotifier {
  HomeController(this._client);

  final HomeApiClient _client;

  HomeStatus _status = HomeStatus.loading;
  HomeSummary? _summary;
  String? _errorMessage;
  bool _isRefreshing = false;
  Future<bool>? _inFlight;
  bool _disposed = false;

  HomeStatus get status => _status;
  HomeSummary? get summary => _summary;

  /// Set when [status] is [HomeStatus.error]: a message safe to show.
  String? get errorMessage => _errorMessage;
  bool get isRefreshing => _isRefreshing;

  /// Initial load and "Try again": shows the full loading state when there
  /// is no data yet. Joins a request already in flight rather than
  /// starting a second one.
  Future<void> load() async {
    if (_inFlight != null) {
      await _inFlight;

      return;
    }

    if (_summary == null) {
      _status = HomeStatus.loading;
      _errorMessage = null;
      _notify();
    }

    await _fetch();
  }

  /// Pull-to-refresh: keeps the current content on screen while reloading.
  /// Returns false when the refresh failed but earlier data is still shown
  /// (so the page can say so); true otherwise.
  Future<bool> refresh() async {
    final inFlight = _inFlight;
    if (inFlight != null) {
      return inFlight;
    }

    _isRefreshing = true;
    _notify();

    return _fetch();
  }

  Future<bool> _fetch() {
    final request = _performFetch();
    _inFlight = request;

    return request.whenComplete(() => _inFlight = null);
  }

  Future<bool> _performFetch() async {
    try {
      final summary = await _client.fetchHome();
      _summary = summary;
      _status = HomeStatus.loaded;
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      // The session has already ended and the app is returning to Login.
      return true;
    } on ApiException catch (e) {
      if (_summary != null) {
        // Keep showing the earlier data.
        return false;
      }

      _status = HomeStatus.error;
      _errorMessage = _messageFor(e);

      return true;
    } finally {
      _isRefreshing = false;
      _notify();
    }
  }

  String _messageFor(ApiException e) => switch (e) {
    ApiNetworkException() => "Couldn't load your Home. Check your connection.",
    // An ordinary refusal whose session is still valid: the server's own
    // sanitized message.
    ApiForbiddenException(:final message) => message,
    _ => 'Something went wrong loading your Home.',
  };

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

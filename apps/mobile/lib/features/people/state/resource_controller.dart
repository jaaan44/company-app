import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';

enum ResourceStatus { loading, loaded, error }

/// Load/refresh state for a screen that shows one fetched object — the
/// Phase 27 `HomeController` lifecycle, shared by the People area's profile
/// and staff-detail screens (Phase 28, spec §7):
///
/// - [load] (initial load and "Try again") shows the full loading state when
///   there is no data yet;
/// - [refresh] (pull-to-refresh) keeps the current content on screen;
/// - one request at a time: a second call joins the one in flight;
/// - a failure with earlier data keeps that data ([refresh] returns false so
///   the page can say so); a failure without data is the error state.
///
/// Session expiry is not handled here: `ApiClient` has already ended the
/// session (and the router has left the shell) by the time an
/// [ApiSessionExpiredException] arrives, so it is simply ignored.
abstract class ResourceController<T> extends ChangeNotifier {
  ResourceStatus _status = ResourceStatus.loading;
  T? _data;
  String? _errorMessage;
  bool _isRefreshing = false;
  Future<bool>? _inFlight;
  bool _disposed = false;

  ResourceStatus get status => _status;
  T? get data => _data;

  /// Set when [status] is [ResourceStatus.error]: a message safe to show.
  String? get errorMessage => _errorMessage;
  bool get isRefreshing => _isRefreshing;

  /// The request this controller loads.
  @protected
  Future<T> fetch();

  /// The on-screen message for a failure with no data to fall back on.
  @protected
  String messageFor(ApiException e);

  Future<void> load() async {
    if (_inFlight != null) {
      await _inFlight;

      return;
    }

    if (_data == null) {
      _status = ResourceStatus.loading;
      _errorMessage = null;
      _notify();
    }

    await _run();
  }

  Future<bool> refresh() async {
    final inFlight = _inFlight;
    if (inFlight != null) {
      return inFlight;
    }

    _isRefreshing = true;
    _notify();

    return _run();
  }

  Future<bool> _run() {
    final request = _perform();
    _inFlight = request;

    return request.whenComplete(() => _inFlight = null);
  }

  Future<bool> _perform() async {
    try {
      _data = await fetch();
      _status = ResourceStatus.loaded;
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      // The session has already ended and the app is returning to Login.
      return true;
    } on ApiException catch (e) {
      if (_data != null) {
        // Keep showing the earlier data.
        return false;
      }

      _status = ResourceStatus.error;
      _errorMessage = messageFor(e);

      return true;
    } finally {
      _isRefreshing = false;
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

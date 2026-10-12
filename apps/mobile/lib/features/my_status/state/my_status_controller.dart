import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

enum MyStatusState { loading, loaded, noProfile, error }

/// The My status screen's state (Phase 30B,
/// docs/phases/V1_PHASE_30_DEFINITION.md §6.4, R-13…R-16): my current
/// status and my [recentCount] newest status changes and check-ins, loaded
/// together.
///
/// - **No profile (R-15):** a `403` that reaches this controller (after
///   `ApiClient`'s `/auth/me` re-check) can only mean no Staff record is
///   linked; it becomes [MyStatusState.noProfile]. No message is parsed.
/// - **Status changes are confirmed (R-16):** [setStatus] changes nothing
///   on screen until the server answers `201`; a failure keeps the old
///   status and sets [saveError].
/// - **Stale responses are dropped:** every load, refresh and confirmed
///   change starts a new generation.
///
/// Owned by its page for the page's lifetime (DEC-025).
class MyStatusController extends ChangeNotifier {
  MyStatusController(this._client);

  static const recentCount = 5;

  final MyStatusApiClient _client;

  MyStatusState _state = MyStatusState.loading;
  ScheduleCompanyDay? _companyDay;
  List<StatusEntry> _statuses = const [];
  List<CheckIn> _checkIns = const [];
  String? _errorMessage;
  bool _isRefreshing = false;
  OperationalStatus? _saving;
  String? _saveError;
  int _generation = 0;
  bool _disposed = false;

  MyStatusState get state => _state;
  ScheduleCompanyDay? get companyDay => _companyDay;

  /// The latest change, or null when I have never set a status.
  StatusEntry? get current => _statuses.isEmpty ? null : _statuses.first;
  List<StatusEntry> get recentStatuses => _statuses;
  List<CheckIn> get recentCheckIns => _checkIns;
  String? get errorMessage => _errorMessage;
  bool get isRefreshing => _isRefreshing;

  /// The status being sent, while [setStatus] waits for the server.
  OperationalStatus? get saving => _saving;

  /// Why the last [setStatus] failed (cleared by the next attempt).
  String? get saveError => _saveError;

  /// Initial load and "Try again", with the full loading state.
  Future<void> load() async {
    _state = MyStatusState.loading;
    _errorMessage = null;
    _notify();
    await _fetch(keepContent: false);
  }

  /// Pull-to-refresh: keeps the content on screen. Returns false when it
  /// failed but earlier content is still shown.
  Future<bool> refresh() {
    _isRefreshing = true;
    _notify();

    return _fetch(keepContent: true);
  }

  Future<bool> _fetch({required bool keepContent}) async {
    final generation = ++_generation;
    try {
      // Both requests run together; Future.wait handles both outcomes, so
      // neither error is ever left unhandled.
      final results = await Future.wait<Object>([
        _client.fetchStatusHistory(perPage: recentCount),
        _client.fetchCheckIns(perPage: recentCount),
      ]);
      if (generation != _generation) {
        return true;
      }
      final statuses = results[0] as MyHistoryPage<StatusEntry>;
      final checkIns = results[1] as MyHistoryPage<CheckIn>;
      _companyDay = statuses.companyDay;
      _statuses = statuses.page.items;
      _checkIns = checkIns.page.items;
      _state = MyStatusState.loaded;
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      return true;
    } on ApiForbiddenException {
      if (generation == _generation) {
        _setNoProfile();
      }

      return true;
    } on ApiException catch (e) {
      if (generation != _generation) {
        return true;
      }
      if (keepContent && _state == MyStatusState.loaded) {
        return false;
      }
      _state = MyStatusState.error;
      _errorMessage = switch (e) {
        ApiNetworkException() =>
          "Couldn't load your status. Check your connection.",
        _ => 'Something went wrong loading your status.',
      };

      return true;
    } finally {
      if (generation == _generation) {
        _isRefreshing = false;
        _notify();
      }
    }
  }

  /// Sends [status] and shows it only once the server confirms (R-16).
  /// Returns true on success. Ignored while another change is sending.
  Future<bool> setStatus(OperationalStatus status) async {
    if (_saving != null || _state != MyStatusState.loaded) {
      return false;
    }
    _saving = status;
    _saveError = null;
    _notify();

    try {
      final entry = await _client.setStatus(status);
      // A load or refresh still in flight predates this change.
      _generation++;
      _isRefreshing = false;
      _statuses = [entry, ..._statuses].take(recentCount).toList();

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiForbiddenException {
      _generation++;
      _setNoProfile();

      return false;
    } on ApiException catch (e) {
      _saveError = switch (e) {
        ApiNetworkException() =>
          "Couldn't change your status. Check your connection.",
        ApiValidationException() => e.message,
        _ => "Couldn't change your status. Please try again.",
      };

      return false;
    } finally {
      _saving = null;
      _notify();
    }
  }

  /// A check-in the server just confirmed: shown on top at once.
  void addCheckIn(CheckIn checkIn) {
    if (_state != MyStatusState.loaded) {
      return;
    }
    _checkIns = [checkIn, ..._checkIns].take(recentCount).toList();
    _notify();
  }

  void _setNoProfile() {
    _statuses = const [];
    _checkIns = const [];
    _state = MyStatusState.noProfile;
    _errorMessage = null;
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

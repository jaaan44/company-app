import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

enum LeaveOverviewStatus { loading, loaded, noProfile, error }

/// State for the Leave screen (Phase 31, R-1): my balances for the company
/// year, my requests newest first (20 a page, "load more"), and — for
/// reviewers who manage someone — the team summary.
///
/// The three reads run together and the screen shows them together. The
/// Phase 27–30 lifecycle: [load] shows the loading state only when there
/// is nothing yet; [refresh] keeps the content and returns false on
/// failure; a response from before a newer [load]/[refresh] is dropped. A
/// `403` is the no-profile state (R-4). Session expiry is ignored.
class LeaveOverviewController extends ChangeNotifier {
  LeaveOverviewController(this._client);

  final LeaveApiClient _client;

  LeaveOverviewStatus _status = LeaveOverviewStatus.loading;
  String? _errorMessage;
  List<LeaveBalance> _balances = const [];
  final List<LeaveRequest> _requests = [];
  ScheduleCompanyDay? _companyDay;
  TeamLeavePage? _team;
  int _nextPage = 2;
  bool _hasMore = false;
  bool _isRefreshing = false;
  bool _isLoadingMore = false;
  bool _loadMoreFailed = false;
  int _generation = 0;
  Future<bool>? _inFlight;
  bool _disposed = false;

  LeaveOverviewStatus get status => _status;
  String? get errorMessage => _errorMessage;
  List<LeaveBalance> get balances => _balances;
  List<LeaveRequest> get requests => List.unmodifiable(_requests);
  ScheduleCompanyDay? get companyDay => _companyDay;
  bool get hasMore => _hasMore;
  bool get isRefreshing => _isRefreshing;
  bool get isLoadingMore => _isLoadingMore;
  bool get loadMoreFailed => _loadMoreFailed;

  /// The year the balances are for (the company year).
  int? get balanceYear => _balances.isEmpty ? null : _balances.first.year;

  /// R-1: offer Team requests.
  bool get hasTeam => _team?.hasTeam ?? false;
  int get teamPendingCount => _team?.pendingCount ?? 0;

  Future<void> load() async {
    if (_inFlight != null) {
      await _inFlight;

      return;
    }
    if (_status != LeaveOverviewStatus.loaded) {
      _status = LeaveOverviewStatus.loading;
      _errorMessage = null;
      _notify();
    }
    await _run();
  }

  Future<bool> refresh() {
    final inFlight = _inFlight;
    if (inFlight != null) {
      return inFlight;
    }
    _isRefreshing = true;
    _notify();

    return _run();
  }

  /// Appends the next page of my requests. A failure keeps the list and
  /// sets [loadMoreFailed].
  Future<void> loadMore() async {
    if (!_hasMore || _isLoadingMore || _inFlight != null) {
      return;
    }
    final generation = _generation;
    _isLoadingMore = true;
    _loadMoreFailed = false;
    _notify();

    try {
      final result = await _client.fetchMyRequests(page: _nextPage);
      if (generation != _generation) {
        return;
      }
      final known = _requests.map((r) => r.publicId).toSet();
      _requests.addAll(
        result.page.items.where((r) => !known.contains(r.publicId)),
      );
      _companyDay = result.companyDay;
      _hasMore = result.page.hasMore;
      _nextPage = result.page.currentPage + 1;
    } on ApiSessionExpiredException {
      // The app is returning to Login.
    } on ApiException {
      if (generation == _generation) {
        _loadMoreFailed = true;
      }
    } finally {
      if (generation == _generation) {
        _isLoadingMore = false;
      }
      _notify();
    }
  }

  Future<bool> _run() {
    final request = _perform();
    _inFlight = request;

    return request.whenComplete(() => _inFlight = null);
  }

  Future<bool> _perform() async {
    final generation = ++_generation;
    _isLoadingMore = false;
    _loadMoreFailed = false;

    try {
      final balances = _client.fetchBalances();
      final mine = _client.fetchMyRequests();
      final team = _client.fetchTeamRequests(perPage: 1);
      // Awaited in turn below; these only stop a later read's failure from
      // going unhandled when an earlier one fails first.
      mine.ignore();
      team.ignore();
      final results = (await balances, await mine, await team);
      if (generation != _generation) {
        return true;
      }
      _balances = results.$1;
      _requests
        ..clear()
        ..addAll(results.$2.page.items);
      _companyDay = results.$2.companyDay;
      _hasMore = results.$2.page.hasMore;
      _nextPage = results.$2.page.currentPage + 1;
      _team = results.$3;
      _status = LeaveOverviewStatus.loaded;
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      return true;
    } on ApiForbiddenException {
      // R-4: `/me/leave-*` refuses only an account without a profile.
      _status = LeaveOverviewStatus.noProfile;

      return true;
    } on ApiException catch (e) {
      if (_status == LeaveOverviewStatus.loaded) {
        return false;
      }
      _status = LeaveOverviewStatus.error;
      _errorMessage = e is ApiNetworkException
          ? "Couldn't load your leave. Check your connection."
          : 'Something went wrong loading your leave.';

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

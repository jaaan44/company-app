import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/leave/state/leave_changes.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

enum LeaveRequestStatusView { loading, loaded, error }

/// Whose request a detail screen shows: mine (R-6, cancel) or one of my
/// direct reports' (R-7, approve or reject).
enum LeaveRequestViewer { mine, team }

/// State for one leave request's detail (Phase 31, R-6/R-7).
///
/// **Loading** follows the Phase 27–30 lifecycle: a list row passes its
/// item as `initial`, so the screen opens with content and refreshes
/// quietly; one request at a time; a failed refresh keeps the content.
///
/// **Actions are confirmed, single-flight, never optimistic or retried.**
/// The shown status changes only when the server answers; the confirmed
/// request goes to [changes] (R-11). A `409` (the request moved on — e.g.
/// already decided or started) shows the server's message and reloads; a
/// `422` shows the field's message.
///
/// "Can cancel" is judged by the company day (R-2b) — from the list that
/// opened the screen, or read from `/me/leave-requests` — never the device
/// clock; the server decides in the end.
class LeaveRequestController extends ChangeNotifier {
  LeaveRequestController(
    this._client,
    this.publicId, {
    required this.viewer,
    LeaveRequest? initial,
    ScheduleCompanyDay? companyDay,
    this.changes,
  }) : _data = initial,
       _companyDay = companyDay, // ignore: prefer_initializing_formals
       _status = initial == null
           ? LeaveRequestStatusView.loading
           : LeaveRequestStatusView.loaded;

  final LeaveApiClient _client;
  final String publicId;
  final LeaveRequestViewer viewer;
  final LeaveChanges? changes;

  LeaveRequestStatusView _status;
  LeaveRequest? _data;
  ScheduleCompanyDay? _companyDay;
  String? _errorMessage;
  String? _actionMessage;
  bool _isRefreshing = false;
  bool _isActing = false;
  Future<bool>? _inFlight;
  int _actionEpoch = 0;
  bool _disposed = false;

  LeaveRequestStatusView get status => _status;
  LeaveRequest? get data => _data;
  String? get errorMessage => _errorMessage;

  /// The outcome of the last failed action, safe to show.
  String? get actionMessage => _actionMessage;
  bool get isRefreshing => _isRefreshing;
  bool get isActing => _isActing;

  /// The company day: from the list that opened the screen, or (mine)
  /// read with the request. Times in the history are shown in it.
  ScheduleCompanyDay? get companyDay => _companyDay;

  /// R-6: Cancel request is offered.
  bool get canCancel {
    final request = _data;
    final today = _companyDay?.date;

    return viewer == LeaveRequestViewer.mine &&
        request != null &&
        today != null &&
        request.canSelfCancel(today);
  }

  /// R-7: Approve and Reject are offered.
  bool get canDecide =>
      viewer == LeaveRequestViewer.team && _data?.status == LeaveStatus.pending;

  void clearActionMessage() {
    if (_actionMessage != null) {
      _actionMessage = null;
      _notify();
    }
  }

  Future<void> load() async {
    if (_inFlight != null) {
      await _inFlight;

      return;
    }
    if (_data == null) {
      _status = LeaveRequestStatusView.loading;
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

  /// Cancels my request with an optional [reason].
  Future<bool> cancel({String? reason}) {
    if (!canCancel) {
      return Future.value(false);
    }

    return _act(() => _client.cancel(publicId, reason: reason));
  }

  /// Approves a team request with an optional [note].
  Future<bool> approve({String? note}) {
    if (!canDecide) {
      return Future.value(false);
    }

    return _act(() => _client.approve(publicId, note: note));
  }

  /// Rejects a team request; [reason] is required (3–1000, checked by the
  /// page before calling).
  Future<bool> reject({required String reason}) {
    if (!canDecide) {
      return Future.value(false);
    }

    return _act(() => _client.reject(publicId, reason: reason));
  }

  Future<bool> _act(Future<LeaveRequest> Function() send) async {
    if (_isActing) {
      return false;
    }
    _isActing = true;
    _actionMessage = null;
    _notify();

    try {
      final updated = await send();
      _actionEpoch++;
      _data = updated;
      _status = LeaveRequestStatusView.loaded;
      changes?.record(updated);

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiValidationException catch (e) {
      _actionMessage =
          e.firstErrorFor('reason') ?? e.firstErrorFor('note') ?? e.message;

      return false;
    } on ApiException catch (e) {
      _actionMessage = switch (e) {
        ApiNetworkException() =>
          "Couldn't send that. Check your connection and try again.",
        ApiForbiddenException(:final message) => message,
        ApiRequestException(statusCode: 404) =>
          'This request is no longer available.',
        ApiRequestException(statusCode: 409, :final message) => message,
        _ => "Couldn't send that. Please try again.",
      };
      if (e is ApiRequestException && e.statusCode == 409 ||
          e is ApiForbiddenException) {
        await _reloadQuietly();
      }

      return false;
    } finally {
      _isActing = false;
      _notify();
    }
  }

  /// After a refusal: show the request as the server has it now. A
  /// failure keeps the earlier data.
  Future<void> _reloadQuietly() async {
    try {
      final fresh = await _fetch();
      _data = fresh;
      if (fresh.status != LeaveStatus.pending) {
        changes?.record(fresh);
      }
    } on ApiException {
      // Keep the earlier data; the message says what happened.
    }
  }

  Future<LeaveRequest> _fetch() => viewer == LeaveRequestViewer.mine
      ? _client.fetchMyRequest(publicId)
      : _client.fetchTeamRequest(publicId);

  Future<bool> _run() {
    final request = _perform();
    _inFlight = request;

    return request.whenComplete(() => _inFlight = null);
  }

  Future<bool> _perform() async {
    final epoch = _actionEpoch;

    try {
      final needsDay = viewer == LeaveRequestViewer.mine;
      final day = needsDay ? _client.fetchMyRequests(perPage: 1) : null;
      // Awaited below; this only stops its failure from going unhandled
      // when the request read fails first.
      day?.ignore();
      final fresh = await _fetch();
      final today = (await day)?.companyDay;
      if (epoch == _actionEpoch) {
        _data = fresh;
      }
      if (today != null) {
        _companyDay = today;
      }
      _status = LeaveRequestStatusView.loaded;
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      return true;
    } on ApiException catch (e) {
      if (_data != null) {
        return false;
      }
      _status = LeaveRequestStatusView.error;
      _errorMessage = switch (e) {
        ApiNetworkException() =>
          "Couldn't load this request. Check your connection.",
        ApiForbiddenException() => "You don't have access to this request.",
        ApiRequestException(statusCode: 404) =>
          'This request is no longer available.',
        _ => 'Something went wrong loading this request.',
      };

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

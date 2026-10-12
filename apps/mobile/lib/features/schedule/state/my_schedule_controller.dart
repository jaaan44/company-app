import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/schedule/data/schedule_api_client.dart';
import 'package:mobile/features/schedule/domain/schedule.dart';

enum ScheduleStatus { loading, loaded, noProfile, error }

/// State for the Schedule tab (Phase 30A, docs/phases/V1_PHASE_30_DEFINITION.md
/// §5.2, R-8): a seven-day window of my items, starting on the company
/// today, moved a week at a time.
///
/// **The company day comes from the server, never the device clock
/// (DEC-052).** The first request asks for a padded range around the
/// device's date (the day before to a week after — nine days), learns
/// the company today from the response, and lays the week out from it.
/// Only if the device clock is so far off that the week isn't covered is
/// a second, exact request made. The device date never labels anything.
///
/// Every load starts a new generation; a response for an older one is
/// ignored, so quick taps on "Next week" never show a stale week.
/// Session expiry is ignored, as in every Phase 27–29 controller.
///
/// Owned by its page for the page's lifetime (DEC-025).
class MyScheduleController extends ChangeNotifier {
  MyScheduleController(this._client, {DateTime Function()? clock})
    : _clock = clock ?? DateTime.now;

  static const windowDays = 7;

  final ScheduleApiClient _client;
  final DateTime Function() _clock;

  ScheduleStatus _status = ScheduleStatus.loading;
  ScheduleCompanyDay? _companyDay;
  String? _weekStart;
  List<ScheduleDay> _days = const [];
  String? _errorMessage;
  bool _isRefreshing = false;
  int _generation = 0;
  bool _disposed = false;

  ScheduleStatus get status => _status;

  /// The server's company day (null until the first response).
  ScheduleCompanyDay? get companyDay => _companyDay;

  /// The first day of the shown week (`YYYY-MM-DD`).
  String? get weekStart => _weekStart;

  /// The shown week's days, each with its items, in server order.
  List<ScheduleDay> get days => _days;
  String? get errorMessage => _errorMessage;
  bool get isRefreshing => _isRefreshing;

  /// Whether the shown week starts on the company today.
  bool get isCurrentWeek =>
      _companyDay != null && _weekStart == _companyDay!.date;

  /// Initial load and "Try again": the week starting on the company today.
  Future<void> load() => _loadWeek(null, keepContent: false);

  /// Pull-to-refresh: reloads the shown week, keeping it on screen.
  /// Returns false when the refresh failed and earlier content is kept.
  Future<bool> refresh() async {
    _isRefreshing = true;
    _notify();

    return _loadWeek(_weekStart, keepContent: true);
  }

  Future<void> nextWeek() => _move(windowDays);

  Future<void> previousWeek() => _move(-windowDays);

  /// Back to the week starting on the company today.
  Future<void> today() => _loadWeek(_companyDay?.date, keepContent: false);

  Future<void> _move(int days) {
    final start = _weekStart;
    if (start == null) {
      return load();
    }

    return _loadWeek(shiftDate(start, days), keepContent: false);
  }

  /// Loads the week starting on [start] — or, when null, on the company
  /// today (learnt from the response).
  Future<bool> _loadWeek(String? start, {required bool keepContent}) async {
    final generation = ++_generation;
    if (!keepContent) {
      _status = ScheduleStatus.loading;
      _errorMessage = null;
      _notify();
    }

    try {
      var response = await _fetch(start);
      if (generation != _generation) {
        return true;
      }

      final weekStart = start ?? response.companyDay.date;
      final weekEnd = shiftDate(weekStart, windowDays - 1);
      if (!response.covers(weekStart, weekEnd)) {
        // The device clock was too far off for the padded guess.
        response = await _client.fetchMySchedule(from: weekStart, to: weekEnd);
        if (generation != _generation) {
          return true;
        }
      }

      _companyDay = response.companyDay;
      _weekStart = weekStart;
      final items = response.items;
      if (items == null) {
        _days = const [];
        _status = ScheduleStatus.noProfile;
      } else {
        _days = groupByDay(items, response.companyDay, weekStart, weekEnd);
        _status = ScheduleStatus.loaded;
      }
      _errorMessage = null;

      return true;
    } on ApiSessionExpiredException {
      return true;
    } on ApiException catch (e) {
      if (generation != _generation) {
        return true;
      }
      if (keepContent && _status == ScheduleStatus.loaded) {
        return false;
      }
      _status = ScheduleStatus.error;
      _errorMessage = _messageFor(e);

      return true;
    } finally {
      if (generation == _generation) {
        _isRefreshing = false;
        _notify();
      }
    }
  }

  Future<MySchedule> _fetch(String? start) {
    if (start != null) {
      return _client.fetchMySchedule(
        from: start,
        to: shiftDate(start, windowDays - 1),
      );
    }

    // The company today is unknown: ask for the device's yesterday to a
    // week after its today, which covers the company week whenever the
    // device clock is within a day of the company date.
    final now = _clock();
    final deviceToday =
        '${now.year.toString().padLeft(4, '0')}-'
        '${now.month.toString().padLeft(2, '0')}-'
        '${now.day.toString().padLeft(2, '0')}';

    return _client.fetchMySchedule(
      from: shiftDate(deviceToday, -1),
      to: shiftDate(deviceToday, windowDays),
    );
  }

  String _messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load your schedule. Check your connection.",
    ApiForbiddenException() => "You don't have access to the schedule.",
    _ => 'Something went wrong loading your schedule.',
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

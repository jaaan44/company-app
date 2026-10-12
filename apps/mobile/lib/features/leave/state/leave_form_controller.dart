import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/leave/state/leave_changes.dart';

enum LeaveFormStatus { loading, ready, noProfile, error }

/// The form's field keys, for [LeaveFormController.fieldErrors].
abstract final class LeaveField {
  static const type = 'leave_type_id';
  static const start = 'start_date';
  static const end = 'end_date';
  static const reason = 'reason';
}

/// State for Request leave (Phase 31, R-5).
///
/// - **Types** are the balances' rows: every active type, with this
///   company year's days left for paid ones. **The company day** is the
///   default for both dates — from the screen that opened the form, or
///   `/me/leave-requests`'s `meta.company_day` — never the device clock.
/// - **Checked before sending:** a type, both dates, end not before start,
///   both in one calendar year, a reason of 3–1000 characters. Past dates
///   are allowed, as the API allows them (sick leave).
/// - **The API stays authoritative:** a `422` is shown on its fields (or
///   at the top for overlap, inactive type or staff), a `409` (balance,
///   overlap race) at the top, in the server's words.
/// - **Submitting is confirmed, single-flight and never retried.** On
///   failure everything typed stays. [isDirty] tells the page whether to
///   ask "Discard this request?".
class LeaveFormController extends ChangeNotifier {
  LeaveFormController(
    this._client, {
    List<LeaveBalance>? balances,
    String? companyDate,
    this.changes,
  }) : _types = balances,
       _companyDate = companyDate {
    _start = companyDate;
    _end = companyDate;
    _snapshot();
  }

  final LeaveApiClient _client;
  final LeaveChanges? changes;

  LeaveFormStatus _status = LeaveFormStatus.loading;
  String? _loadError;
  List<LeaveBalance>? _types;
  String? _companyDate;

  LeaveBalance? _type;
  String? _start;
  String? _end;
  String _reason = '';
  late String _initial;

  final Map<String, String> _fieldErrors = {};
  String? _generalError;
  bool _isSubmitting = false;
  LeaveRequest? _submitted;
  bool _disposed = false;

  LeaveFormStatus get status => _status;
  String? get loadError => _loadError;

  /// The choosable types (active ones), in the API's order.
  List<LeaveBalance> get types => _types ?? const [];
  String? get companyDate => _companyDate;

  LeaveBalance? get type => _type;
  String? get startDate => _start;
  String? get endDate => _end;
  String get reason => _reason;

  /// The days the request covers, weekends included; null until both
  /// dates are set and in order.
  int? get days {
    final s = _start;
    final e = _end;
    if (s == null || e == null) {
      return null;
    }
    final n = calendarDaysInclusive(s, e);

    return n == 0 ? null : n;
  }

  Map<String, String> get fieldErrors => Map.unmodifiable(_fieldErrors);
  String? get generalError => _generalError;
  bool get isSubmitting => _isSubmitting;

  /// The server's request after a confirmed submit.
  LeaveRequest? get submitted => _submitted;
  bool get isDirty => _state() != _initial;

  Future<void> load() async {
    _status = LeaveFormStatus.loading;
    _loadError = null;
    _notify();

    try {
      if (_companyDate == null) {
        final day = (await _client.fetchMyRequests(perPage: 1)).companyDay;
        _companyDate = day.date;
        _start ??= day.date;
        _end ??= day.date;
        _snapshot();
      }
      _types ??= await _client.fetchBalances();
      _status = LeaveFormStatus.ready;
    } on ApiSessionExpiredException {
      // The app is returning to Login.
    } on ApiForbiddenException {
      _status = LeaveFormStatus.noProfile;
    } on ApiException catch (e) {
      _status = LeaveFormStatus.error;
      _loadError = e is ApiNetworkException
          ? "Couldn't load the form. Check your connection."
          : 'Something went wrong loading the form.';
    } finally {
      _notify();
    }
  }

  void setType(LeaveBalance? value) {
    _type = value;
    _clearError(LeaveField.type);
  }

  /// Sets the start date; an end date before it moves to it.
  void setStartDate(String value) {
    _start = value;
    final end = _end;
    if (end == null || end.compareTo(value) < 0) {
      _end = value;
    }
    _fieldErrors.remove(LeaveField.end);
    _clearError(LeaveField.start);
  }

  void setEndDate(String value) {
    _end = value;
    _clearError(LeaveField.end);
  }

  void setReason(String value) {
    _reason = value;
    _clearError(LeaveField.reason);
  }

  /// The selected paid type's balance line for the chosen year, e.g.
  /// "5 days left in 2026"; null for an unpaid type, or when the request
  /// is for another year than the balances (the API checks that year).
  String? get balanceHint {
    final t = _type;
    final start = _start;
    final remaining = t?.remainingDays;
    if (t == null || remaining == null || start == null) {
      return null;
    }
    if (int.parse(start.substring(0, 4)) != t.year) {
      return null;
    }

    return '${daysLabel(remaining)} left in ${t.year}';
  }

  bool validate() {
    _fieldErrors.clear();
    _generalError = null;

    if (_type == null) {
      _fieldErrors[LeaveField.type] = 'Choose a type of leave.';
    }
    final start = _start;
    final end = _end;
    if (start == null) {
      _fieldErrors[LeaveField.start] = 'Choose the first day.';
    }
    if (end == null) {
      _fieldErrors[LeaveField.end] = 'Choose the last day.';
    }
    if (start != null && end != null) {
      if (end.compareTo(start) < 0) {
        _fieldErrors[LeaveField.end] =
            'The last day cannot be before the first day.';
      } else if (start.substring(0, 4) != end.substring(0, 4)) {
        _fieldErrors[LeaveField.end] =
            'A request must stay within one calendar year. '
            'Make one request for each year.';
      }
    }
    final text = _reason.trim();
    if (text.length < LeaveLimits.minReasonLength) {
      _fieldErrors[LeaveField.reason] =
          'Give a reason (at least ${LeaveLimits.minReasonLength} characters).';
    } else if (text.length > LeaveLimits.maxReasonLength) {
      _fieldErrors[LeaveField.reason] =
          'Use at most ${LeaveLimits.maxReasonLength} characters.';
    }
    _notify();

    return _fieldErrors.isEmpty;
  }

  /// Submits once, after [validate]. Returns true when the server
  /// confirmed it; the request is then [submitted] and in [changes].
  Future<bool> submit() async {
    if (_isSubmitting || _status != LeaveFormStatus.ready || !validate()) {
      return false;
    }
    _isSubmitting = true;
    _notify();

    try {
      final created = await _client.submit(
        LeaveDraft(
          leaveTypeId: _type!.leaveType.publicId,
          startDate: _start!,
          endDate: _end!,
          reason: _reason,
        ),
      );
      _submitted = created;
      changes?.record(created);
      _snapshot();

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiValidationException catch (e) {
      for (final entry in e.fieldErrors.entries) {
        if (entry.value.isEmpty) {
          continue;
        }
        if (const [
          LeaveField.start,
          LeaveField.end,
          LeaveField.reason,
        ].contains(entry.key)) {
          _fieldErrors[entry.key] = entry.value.first;
        } else {
          // leave_type_id (inactive), staff_id: shown at the top.
          _generalError ??= entry.value.first;
        }
      }
      if (_fieldErrors.isEmpty && _generalError == null) {
        _generalError = e.message;
      }

      return false;
    } on ApiException catch (e) {
      _generalError = switch (e) {
        ApiNetworkException() =>
          "Couldn't send the request. Check your connection and try again.",
        ApiForbiddenException(:final message) => message,
        // 409: not enough balance, or an overlap found at the last moment.
        ApiRequestException(statusCode: 409, :final message) => message,
        _ => "Couldn't send the request. Please try again.",
      };

      return false;
    } finally {
      _isSubmitting = false;
      _notify();
    }
  }

  void _clearError(String field) {
    _fieldErrors.remove(field);
    _generalError = null;
    _notify();
  }

  String _state() => '${_type?.leaveType.publicId}|$_start|$_end|$_reason';

  void _snapshot() => _initial = _state();

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

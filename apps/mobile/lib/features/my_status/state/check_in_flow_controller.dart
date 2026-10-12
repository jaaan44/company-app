import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';
import 'package:mobile/features/my_status/location/location_service.dart';

/// Where the check-in flow is (R-19/R-20).
enum CheckInStep {
  /// Looking at the device's location setting and permission.
  checking,

  /// The in-app explanation, shown before Android's prompt (R-19).
  explaining,

  /// Reading one position.
  locating,

  /// A position is ready; the form can be filled and sent.
  ready,
  sending,

  /// The server confirmed the check-in ([CheckInFlowController.sent]).
  sent,

  // R-20: each ends the attempt with nothing sent.
  serviceOff,
  denied,
  deniedForever,
  timedOut,

  /// No Staff record is linked (R-15).
  noProfile,
}

/// One explicit, foreground check-in (Phase 30B,
/// docs/phases/V1_PHASE_30_DEFINITION.md §6.4, R-19/R-20): permission only
/// after the explanation, **one** position, an optional place and note, one
/// `POST`. Nothing is ever sent without a position, nothing is queued, and
/// no status snapshot is sent.
///
/// Owned by its page for the page's lifetime (DEC-025).
class CheckInFlowController extends ChangeNotifier {
  CheckInFlowController(
    this._client,
    this._location, {
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now;

  /// How long to wait for a position.
  static const locateTimeout = Duration(seconds: 30);

  /// A fix older than this is read again before sending (R-19).
  static const maxFixAge = Duration(minutes: 2);

  final MyStatusApiClient _client;
  final LocationService _location;

  /// Device clock: only measures the fix's age, never a business date.
  final DateTime Function() _clock;

  CheckInStep _step = CheckInStep.checking;
  LocationFix? _fix;
  CheckIn? _sent;
  String? _sendError;
  bool _disposed = false;

  CheckInStep get step => _step;

  /// The position to send, once [CheckInStep.ready].
  LocationFix? get fix => _fix;

  /// The confirmed check-in, once [CheckInStep.sent].
  CheckIn? get sent => _sent;

  /// Why the last send failed; the form stays filled (R-19).
  String? get sendError => _sendError;

  /// The R-20 message for an error step, else null.
  String? get problem => switch (_step) {
    CheckInStep.serviceOff => 'Turn on location to check in.',
    CheckInStep.denied ||
    CheckInStep.deniedForever => 'Location permission is needed to check in.',
    CheckInStep.timedOut =>
      "Couldn't get your location. Try again, ideally outdoors or near a "
          'window.',
    CheckInStep.noProfile => 'No staff profile is linked to this account.',
    _ => null,
  };

  bool get _busy =>
      _step == CheckInStep.locating || _step == CheckInStep.sending;

  /// Begins (or, after an error, retries) the flow. The explanation comes
  /// first unless the permission is already granted.
  Future<void> start() async {
    if (_busy) {
      return;
    }
    _fix = null;
    _sendError = null;
    _go(CheckInStep.checking);

    if (!await _location.serviceEnabled()) {
      _go(CheckInStep.serviceOff);

      return;
    }
    switch (await _location.permission()) {
      case LocationPermissionState.granted:
        await _locate();
      case LocationPermissionState.denied:
        _go(CheckInStep.explaining);
      case LocationPermissionState.deniedForever:
        _go(CheckInStep.deniedForever);
    }
  }

  /// **Continue** on the explanation: Android's prompt, then the position.
  Future<void> continueAfterExplanation() async {
    if (_step != CheckInStep.explaining) {
      return;
    }
    switch (await _location.requestPermission()) {
      case LocationPermissionState.granted:
        await _locate();
      case LocationPermissionState.denied:
        _go(CheckInStep.denied);
      case LocationPermissionState.deniedForever:
        _go(CheckInStep.deniedForever);
    }
  }

  /// Sends the check-in with an optional place and note. A fix older than
  /// [maxFixAge] is read again first. Returns true once confirmed.
  Future<bool> send({String? locationLabel, String? note}) async {
    if (_step != CheckInStep.ready) {
      return false;
    }
    _sendError = null;

    if (_clock().difference(_fix!.obtainedAt) > maxFixAge) {
      await _locate();
      if (_step != CheckInStep.ready) {
        return false;
      }
    }

    _go(CheckInStep.sending);
    try {
      _sent = await _client.checkIn(
        CheckInDraft(fix: _fix!, locationLabel: locationLabel, note: note),
      );
      _go(CheckInStep.sent);

      return true;
    } on ApiSessionExpiredException {
      _go(CheckInStep.ready);

      return false;
    } on ApiForbiddenException {
      _go(CheckInStep.noProfile);

      return false;
    } on ApiException catch (e) {
      _sendError = switch (e) {
        ApiNetworkException() =>
          "Couldn't send your check-in. Check your connection and try "
              'again.',
        ApiValidationException() => e.message,
        _ => "Couldn't send your check-in. Please try again.",
      };
      _go(CheckInStep.ready);

      return false;
    }
  }

  Future<bool> openAppSettings() => _location.openAppSettings();

  Future<bool> openLocationSettings() => _location.openLocationSettings();

  Future<void> _locate() async {
    _go(CheckInStep.locating);
    try {
      _fix = await _location.currentPosition(timeout: locateTimeout);
      _go(CheckInStep.ready);
    } on LocationServiceOffException {
      _fix = null;
      _go(CheckInStep.serviceOff);
    } on LocationPermissionException {
      _fix = null;
      _go(CheckInStep.denied);
    } on LocationTimeoutException {
      _fix = null;
      _go(CheckInStep.timedOut);
    }
  }

  void _go(CheckInStep step) {
    _step = step;
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

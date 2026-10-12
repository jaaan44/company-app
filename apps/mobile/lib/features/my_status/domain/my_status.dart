/// Typed views of the Phase 9 operational-status and check-in API as the
/// mobile My status area uses it — Phase 30B,
/// docs/phases/V1_PHASE_30_DEFINITION.md §6.4.
///
/// Parsing is strict for the structure (a missing or mistyped required
/// field, a bad instant or company day throws a [FormatException]); a
/// status the app doesn't know is kept as [OperationalStatus.unknown], so a
/// new server value never breaks the screen.
library;

import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay, instant;

/// An operational status (Phase 9, DEC-032). Not employment status, not
/// attendance, not leave.
enum OperationalStatus {
  available('available', 'Available'),
  busy('busy', 'Busy'),
  inMeeting('in_meeting', 'In a meeting'),
  inField('in_field', 'In the field'),
  offDuty('off_duty', 'Off duty'),

  /// A value this version of the app doesn't know. Shown, never sent.
  unknown('', 'Unknown status');

  const OperationalStatus(this.wireValue, this.label);

  final String wireValue;
  final String label;

  /// The five a person can choose (R-16).
  static const choices = [available, busy, inMeeting, inField, offDuty];

  /// R-16: Off duty says it isn't leave.
  String? get hint =>
      this == offDuty ? "Not working right now. This isn't leave." : null;

  static OperationalStatus fromWire(Object? value) {
    if (value is! String) {
      throw FormatException('Not a status: $value');
    }

    return choices.firstWhere(
      (s) => s.wireValue == value,
      orElse: () => unknown,
    );
  }
}

/// One status change (`OperationalStatusResource`).
class StatusEntry {
  const StatusEntry({required this.status, required this.recordedAt});

  factory StatusEntry.fromJson(Map<String, dynamic> json) {
    try {
      return StatusEntry(
        status: OperationalStatus.fromWire(json['status']),
        recordedAt: instant(json['recorded_at']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected status shape: $e');
    }
  }

  final OperationalStatus status;

  /// UTC.
  final DateTime recordedAt;
}

/// One check-in (`CheckInResource`). Immutable once recorded.
class CheckIn {
  const CheckIn({
    required this.publicId,
    required this.latitude,
    required this.longitude,
    required this.checkedInAt,
    this.accuracyMeters,
    this.locationLabel,
    this.note,
  });

  factory CheckIn.fromJson(Map<String, dynamic> json) {
    try {
      return CheckIn(
        publicId: json['public_id'] as String,
        latitude: (json['latitude'] as num).toDouble(),
        longitude: (json['longitude'] as num).toDouble(),
        accuracyMeters: json['accuracy_meters'] as int?,
        locationLabel: json['location_label'] as String?,
        note: json['note'] as String?,
        checkedInAt: instant(json['checked_in_at']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected check-in shape: $e');
    }
  }

  final String publicId;
  final double latitude;
  final double longitude;
  final int? accuracyMeters;
  final String? locationLabel;
  final String? note;

  /// UTC.
  final DateTime checkedInAt;
}

/// One page of `/me/status` or `/me/check-ins` with its company day
/// (Gate 1, R-14).
class MyHistoryPage<T> {
  const MyHistoryPage({required this.companyDay, required this.page});

  factory MyHistoryPage.fromJson(
    Map<String, dynamic> json,
    T Function(Map<String, dynamic> json) item,
  ) {
    try {
      final meta = json['meta'] as Map<String, dynamic>;

      return MyHistoryPage(
        companyDay: ScheduleCompanyDay.fromJson(
          meta['company_day'] as Map<String, dynamic>,
        ),
        page: PagedResult.fromJson(json, item),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected history page shape: $e');
    }
  }

  final ScheduleCompanyDay companyDay;
  final PagedResult<T> page;
}

/// The API's limits on a check-in (Phase 9 `StoreCheckInRequest`),
/// mirrored so the form refuses impossible input before sending; the API
/// stays authoritative.
abstract final class CheckInLimits {
  static const maxLabelLength = 100;
  static const maxNoteLength = 500;
  static const maxAccuracyMeters = 100000;
}

/// One position read from the device for a check-in (R-19).
class LocationFix {
  const LocationFix({
    required this.latitude,
    required this.longitude,
    required this.accuracyMeters,
    required this.isApproximate,
    required this.obtainedAt,
  });

  final double latitude;
  final double longitude;

  /// The device's estimate, in metres.
  final double accuracyMeters;

  /// The person granted Approximate location only (Android 12+).
  final bool isApproximate;

  /// When the app received it (device clock: only used to tell how old
  /// the fix is, never as a business date).
  final DateTime obtainedAt;
}

/// What `POST /me/check-ins` is sent (R-19): the fix, an optional place
/// and note, and **no status snapshot**.
class CheckInDraft {
  const CheckInDraft({required this.fix, this.locationLabel, this.note});

  final LocationFix fix;
  final String? locationLabel;
  final String? note;

  /// The request body: blank text is sent as null; the accuracy is rounded
  /// and kept within the API's range.
  Map<String, dynamic> toJson() {
    String? text(String? value) {
      final trimmed = value?.trim() ?? '';

      return trimmed.isEmpty ? null : trimmed;
    }

    return {
      'latitude': fix.latitude,
      'longitude': fix.longitude,
      'accuracy_meters': fix.accuracyMeters.round().clamp(
        0,
        CheckInLimits.maxAccuracyMeters,
      ),
      'location_label': text(locationLabel),
      'note': text(note),
    };
  }
}

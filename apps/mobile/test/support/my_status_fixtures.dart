import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';
import 'package:mobile/features/my_status/location/location_service.dart';

import 'fake_backend.dart';

/// Payloads in the exact Phase 9 `OperationalStatusResource` and
/// `CheckInResource` shapes, with the Phase 30B Gate 1 `meta.company_day`.

const manilaCompanyDay = {
  'date': '2026-10-14',
  'timezone': 'Asia/Manila',
  'utc_offset': '+08:00',
};

Map<String, dynamic> statusJson(
  String status, {
  String recordedAt = '2026-10-14T01:00:00+00:00',
}) => {'status': status, 'recorded_at': recordedAt};

Map<String, dynamic> checkInJson({
  String publicId = '01J0CHECKIN000000000000AAA',
  Object latitude = 14.5547,
  Object longitude = 121.0244,
  Object? accuracy = 12,
  Object? label = 'Client site',
  Object? note = 'Gate B',
  Object? status,
  String checkedInAt = '2026-10-14T02:30:00+00:00',
}) => {
  'public_id': publicId,
  'latitude': latitude,
  'longitude': longitude,
  'accuracy_meters': accuracy,
  'location_label': label,
  'note': note,
  'status': status,
  'checked_in_at': checkedInAt,
};

/// A paginator body as Laravel sends it, plus the company day.
Map<String, dynamic> historyBody(
  List<Map<String, dynamic>> items, {
  int page = 1,
  int lastPage = 1,
  int? total,
  int perPage = 20,
  Map<String, dynamic>? companyDay = manilaCompanyDay,
}) => {
  'data': items,
  'links': {'first': null, 'last': null, 'prev': null, 'next': null},
  'meta': {
    'current_page': page,
    'last_page': lastPage,
    'per_page': perPage,
    'total': total ?? items.length,
    'company_day': ?companyDay,
  },
};

http.Response created(Map<String, dynamic> data) => http.Response.bytes(
  utf8.encode(jsonEncode({'data': data})),
  201,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

MyStatusApiClient myStatusClientFor(
  FakeBackend backend,
  AuthController session,
) => MyStatusApiClient(
  ApiClient(session: session, httpClient: backend.client, baseUrl: testBaseUrl),
);

LocationFix fixAt(
  DateTime obtainedAt, {
  double latitude = 14.5547123,
  double longitude = 121.0244456,
  double accuracy = 8.6,
  bool approximate = false,
}) => LocationFix(
  latitude: latitude,
  longitude: longitude,
  accuracyMeters: accuracy,
  isApproximate: approximate,
  obtainedAt: obtainedAt,
);

/// A scriptable device: its setting, its permission (and what the prompt
/// answers), and what each position read returns or throws. Counts every
/// call so tests can prove what was — and wasn't — asked.
class FakeLocationService implements LocationService {
  bool enabled = true;
  LocationPermissionState current = LocationPermissionState.granted;

  /// What Android's prompt answers; it becomes [current].
  LocationPermissionState onRequest = LocationPermissionState.granted;

  /// Answers each read in turn; the last one repeats. An [Exception] is
  /// thrown; a [Completer] is awaited.
  List<Object> reads = [];

  int serviceChecks = 0;
  int permissionChecks = 0;
  int requests = 0;
  int positionReads = 0;
  int appSettingsOpened = 0;
  int locationSettingsOpened = 0;
  Duration? lastTimeout;

  @override
  Future<bool> serviceEnabled() async {
    serviceChecks++;

    return enabled;
  }

  @override
  Future<LocationPermissionState> permission() async {
    permissionChecks++;

    return current;
  }

  @override
  Future<LocationPermissionState> requestPermission() async {
    requests++;
    current = onRequest;

    return current;
  }

  @override
  Future<LocationFix> currentPosition({required Duration timeout}) async {
    lastTimeout = timeout;
    final answer =
        reads[positionReads < reads.length ? positionReads : reads.length - 1];
    positionReads++;
    if (answer is Exception) {
      throw answer;
    }
    if (answer is Completer<LocationFix>) {
      return answer.future;
    }

    return answer as LocationFix;
  }

  @override
  Future<bool> openAppSettings() async {
    appSettingsOpened++;

    return true;
  }

  @override
  Future<bool> openLocationSettings() async {
    locationSettingsOpened++;

    return true;
  }
}

import 'dart:convert';

import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/home/data/home_api_client.dart';

import 'fake_backend.dart';

/// `GET /api/v1/me/home` payloads in the exact Gate 1 contract shape
/// (docs/phases/V1_PHASE_27_DEFINITION.md §6.2). The default company day
/// is 2026-09-24 in a UTC+09:00 company timezone, so company-time display
/// is distinguishable from UTC.

Map<String, dynamic> companyDayJson({
  String date = '2026-09-24',
  String timezone = 'Asia/Tokyo',
  String startsAt = '2026-09-23T15:00:00+00:00',
  String endsAt = '2026-09-24T14:59:59+00:00',
  String utcOffset = '+09:00',
}) => {
  'date': date,
  'timezone': timezone,
  'starts_at': startsAt,
  'ends_at': endsAt,
  'utc_offset': utcOffset,
};

Map<String, dynamic> staffJson({
  String firstName = 'Ada',
  String? preferredName,
  String? position = 'Field Technician',
  String? department = 'Operations',
  String? team,
}) => {
  'public_id': '01STAFF00000000000000000AA',
  'display_name': preferredName ?? '$firstName Lovelace',
  'first_name': firstName,
  'preferred_name': preferredName,
  'position': position == null
      ? null
      : {'public_id': '01POS000000000000000000000', 'title': position},
  'department': department == null
      ? null
      : {'public_id': '01DEP000000000000000000000', 'name': department},
  'team': team == null
      ? null
      : {'public_id': '01TEAM00000000000000000000', 'name': team},
};

Map<String, dynamic> scheduleItemJson({
  String publicId = '01ENTRY0000000000000000001',
  String title = 'Site visit — Acme',
  String activityType = 'client_visit',
  String startsAt = '2026-09-24T00:00:00+00:00', // 09:00 company time
  String endsAt = '2026-09-24T01:30:00+00:00', // 10:30 company time
  bool isAllDay = false,
}) => {
  'source_type': 'schedule_entry',
  'public_id': publicId,
  'title': title,
  'activity_type': activityType,
  'task_status': null,
  'starts_at': startsAt,
  'ends_at': endsAt,
  'is_all_day': isAllDay,
};

Map<String, dynamic> taskItemJson({
  String publicId = '01TASK00000000000000000001',
  String title = 'Replace filter unit',
  String status = 'in_progress',
}) => {
  'source_type': 'task',
  'public_id': publicId,
  'title': title,
  'activity_type': null,
  'task_status': status,
  'starts_at': '2026-09-23T15:00:00+00:00',
  'ends_at': '2026-09-24T14:59:59+00:00',
  'is_all_day': true,
};

Map<String, dynamic> announcementJson({
  String publicId = '01ANN000000000000000000001',
  String title = 'Office closed Friday',
  String? publishedAt = '2026-09-22T09:00:00+00:00',
}) => {'public_id': publicId, 'title': title, 'published_at': publishedAt};

/// The `data` object. Pass `staff: null` for the no-profile variant (every
/// Staff-dependent section then defaults to null too).
Map<String, dynamic> homeDataJson({
  Map<String, dynamic>? staff = const {},
  List<Map<String, dynamic>>? todayItems,
  int? todayTotal,
  Map<String, int>? tasks,
  int? unreadMessages,
  int unreadNotifications = 5,
  List<Map<String, dynamic>>? announcements,
  Map<String, dynamic>? companyDay,
}) {
  final hasStaff = staff != null;
  final items = todayItems ?? [scheduleItemJson(), taskItemJson()];

  return {
    'user': {
      'public_id': '01USER00000000000000000001',
      'name': 'Ada Lovelace',
      'role': 'staff',
    },
    'staff': hasStaff ? (staff.isEmpty ? staffJson() : staff) : null,
    'company_day': companyDay ?? companyDayJson(),
    'today': hasStaff
        ? {'total_count': todayTotal ?? items.length, 'items': items}
        : null,
    'tasks': hasStaff
        ? (tasks ?? {'open_count': 4, 'overdue_count': 1, 'due_today_count': 2})
        : null,
    'messages': hasStaff ? {'unread_count': unreadMessages ?? 3} : null,
    'notifications': {'unread_count': unreadNotifications},
    'announcements': hasStaff
        ? {
            'latest': announcements ?? [announcementJson()],
          }
        : null,
  };
}

/// UTF-8 bytes with an explicit charset, so non-ASCII titles round-trip.
/// (Laravel's JsonResponse escapes non-ASCII as \uXXXX by default, so real
/// responses are plain ASCII either way.)
http.Response homeResponse([Map<String, dynamic>? data]) => http.Response.bytes(
  utf8.encode(jsonEncode({'data': data ?? homeDataJson()})),
  200,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

/// A [HomeApiClient] over the real Gate 2 [ApiClient] and [FakeBackend].
HomeApiClient homeClientFor(FakeBackend backend, ApiSession session) =>
    HomeApiClient(
      ApiClient(
        session: session,
        httpClient: backend.client,
        baseUrl: testBaseUrl,
      ),
    );

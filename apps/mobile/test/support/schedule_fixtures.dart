import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/schedule/data/schedule_api_client.dart';

import 'fake_backend.dart';

/// Payloads in the exact Phase 30A `GET /me/schedule` and Phase 17
/// `ScheduleEntryResource` shapes, including fields the app ignores
/// (`created_at`, employee numbers).

const manilaDay = {
  'date': '2026-10-14',
  'timezone': 'Asia/Manila',
  'utc_offset': '+08:00',
};

Map<String, dynamic> scheduleItemJson({
  String source = 'schedule_entry',
  String publicId = '01J0ITEM0000000000000000AA',
  String title = 'Site visit',
  String startsAt = '2026-10-14T01:00:00+00:00',
  String endsAt = '2026-10-14T02:00:00+00:00',
  bool isAllDay = false,
  Object? activityType = 'meeting',
  Object? status,
  Object? taskStatus,
  Object? project,
  Object? description,
}) => {
  'source_type': source,
  'public_id': publicId,
  'title': title,
  'description': description,
  'activity_type': source == 'schedule_entry' ? activityType : null,
  'status': status,
  'task_status': taskStatus,
  'starts_at': startsAt,
  'ends_at': endsAt,
  'is_all_day': isAllDay,
  'project': project,
};

/// An all-day task on company date [date] in Manila.
Map<String, dynamic> manilaTaskJson(
  String date, {
  String title = 'Order filters',
  String id = '01J0TASK0000000000000000AA',
}) {
  final d = DateTime.parse(date);
  final start = DateTime.utc(
    d.year,
    d.month,
    d.day,
  ).subtract(const Duration(hours: 8));
  final end = start.add(const Duration(hours: 23, minutes: 59, seconds: 59));

  return scheduleItemJson(
    source: 'task',
    publicId: id,
    title: title,
    startsAt: start.toIso8601String(),
    endsAt: end.toIso8601String(),
    isAllDay: true,
    status: 'todo',
    taskStatus: 'todo',
  );
}

Map<String, dynamic> myScheduleJson(
  List<Map<String, dynamic>>? items, {
  String from = '2026-10-14',
  String to = '2026-10-20',
  Map<String, dynamic> companyDay = manilaDay,
}) => {
  'data': {
    'company_day': companyDay,
    'range': {'from': from, 'to': to},
    'items': items,
  },
};

Map<String, dynamic> scheduleEntryJson({
  String publicId = '01J0ITEM0000000000000000AA',
  Object? participants,
}) => {
  'public_id': publicId,
  'title': 'Site visit',
  'description': 'Bring the keys.',
  'activity_type': 'client_visit',
  'starts_at': '2026-10-14T01:00:00+00:00',
  'ends_at': '2026-10-14T02:30:00+00:00',
  'is_all_day': false,
  'project': {'public_id': '01J0PROJ000000000000000AAA', 'name': 'Plant Room'},
  'creator': {
    'public_id': 'S1',
    'employee_number': 'EMP-1',
    'display_name': 'Ada Lovelace',
  },
  'participants':
      participants ??
      [
        {
          'public_id': 'S2',
          'employee_number': 'EMP-2',
          'display_name': 'Grace Hopper',
        },
      ],
  'created_at': '2026-10-01T00:00:00+00:00',
  'updated_at': '2026-10-01T00:00:00+00:00',
};

ScheduleApiClient scheduleClientFor(
  FakeBackend backend,
  AuthController session,
) => ScheduleApiClient(
  ApiClient(session: session, httpClient: backend.client, baseUrl: testBaseUrl),
);

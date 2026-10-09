import 'dart:convert';

import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/people/data/people_api_client.dart';

import 'fake_backend.dart';

/// Payloads in the exact Phase 7 `StaffResource` / Phase 28 `/me/profile`
/// contract shapes (docs/phases/V1_PHASE_28_DEFINITION.md §3.1, §6.1),
/// including the fields the app deliberately ignores (`operational_status`,
/// `has_user_account`, timestamps), so parsing is tested against the real
/// shape rather than a trimmed one.

const _absent = Object();

Map<String, dynamic> staffMemberJson({
  String publicId = '01J0STAFF000000000000000AA',
  String firstName = 'Ada',
  String lastName = 'Lovelace',
  Object? preferredName,
  String? displayName,
  Object? position = _absent,
  Object? department = _absent,
  Object? team = _absent,
  Object? manager = _absent,
  String status = 'active',
}) => {
  'public_id': publicId,
  'employee_number': 'EMP-1001',
  'first_name': firstName,
  'last_name': lastName,
  'preferred_name': preferredName,
  'display_name':
      displayName ?? (preferredName as String?) ?? '$firstName $lastName',
  'company_email': '${firstName.toLowerCase()}@company.test',
  'company_phone': '+63 2 8123 4567',
  'status': status,
  'hire_date': '2024-03-01',
  'separation_date': null,
  'department': identical(department, _absent)
      ? {'public_id': '01J0DEPT0000000000000000AA', 'name': 'Operations'}
      : department,
  'team': identical(team, _absent)
      ? {'public_id': '01J0TEAM0000000000000000AA', 'name': 'Field Team A'}
      : team,
  'position': identical(position, _absent)
      ? {'public_id': '01J0POS00000000000000000AA', 'title': 'Field Technician'}
      : position,
  'manager': identical(manager, _absent)
      ? {
          'public_id': '01J0MGR00000000000000000AA',
          'display_name': 'Grace Hopper',
        }
      : manager,
  'has_user_account': true,
  'operational_status': 'available',
  'created_at': '2024-03-01T00:00:00+00:00',
  'updated_at': '2024-03-01T00:00:00+00:00',
};

Map<String, dynamic> profileDataJson({
  Object? staff = _absent,
  String? role = 'staff',
}) => {
  'user': {
    'public_id': '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'name': 'Ada Lovelace',
    'email': 'ada@example.com',
    'role': role,
  },
  'staff': identical(staff, _absent) ? staffMemberJson() : staff,
};

/// One `GET /staff` page in Laravel's paginator shape.
Map<String, dynamic> directoryPageJson(
  List<Map<String, dynamic>> items, {
  int page = 1,
  int lastPage = 1,
}) => {
  'data': items,
  'links': {'first': null, 'last': null, 'prev': null, 'next': null},
  'meta': {
    'current_page': page,
    'last_page': lastPage,
    'per_page': PeopleApiClient.directoryPageSize,
    'total': items.length,
  },
};

/// `count` distinct staff, named `<prefix> 0`, `<prefix> 1`, …
List<Map<String, dynamic>> staffList(int count, {String prefix = 'Person'}) => [
  for (var i = 0; i < count; i++)
    staffMemberJson(
      publicId:
          '01J0${prefix.toUpperCase().padRight(8, 'X')}${'$i'.padLeft(14, '0')}',
      firstName: prefix,
      lastName: '$i',
    ),
];

/// UTF-8 bytes with an explicit charset, so non-ASCII names round-trip.
http.Response jsonResponse(Map<String, dynamic> body) => http.Response.bytes(
  utf8.encode(jsonEncode(body)),
  200,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

/// A [PeopleApiClient] over the real Phase 27 [ApiClient] and [FakeBackend].
PeopleApiClient peopleClientFor(FakeBackend backend, ApiSession session) =>
    PeopleApiClient(
      ApiClient(
        session: session,
        httpClient: backend.client,
        baseUrl: testBaseUrl,
      ),
    );

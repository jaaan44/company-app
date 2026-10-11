import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/clients/data/clients_api_client.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';

import 'fake_backend.dart';

/// Payloads in the exact Phase 8/10/17 resource shapes
/// (docs/phases/V1_PHASE_29_DEFINITION.md §7.1), including the fields the
/// app deliberately ignores (`notes`, timestamps — R-24), so parsing is
/// tested against the real shape.

const _absent = Object();

Map<String, dynamic> projectJson({
  String publicId = '01J0PROJ000000000000000AAA',
  String name = 'Boiler Upgrade',
  String status = 'active',
  Object? projectCode = 'PRJ-001',
  Object? description = 'Replace the plant-room boiler.',
  Object? startDate = '2026-09-01',
  Object? targetEndDate = '2026-12-15',
  Object? completedDate,
  Object? client = _absent,
  Object? myRole = 'member',
  Object? membersCount = 3,
}) => {
  'public_id': publicId,
  'project_code': projectCode,
  'name': name,
  'description': description,
  'status': status,
  'start_date': startDate,
  'target_end_date': targetEndDate,
  'completed_date': completedDate,
  'client': identical(client, _absent)
      ? {'public_id': '01J0CLNT000000000000000AAA', 'name': 'Acme Corp'}
      : client,
  'members_count': membersCount,
  'my_role': myRole,
  'notes': 'Internal: renegotiate the parts contract.',
  'created_at': '2026-09-01T00:00:00+00:00',
  'updated_at': '2026-09-01T00:00:00+00:00',
};

Map<String, dynamic> memberJson({
  String publicId = '01J0STAFF000000000000000AA',
  String displayName = 'Ada Lovelace',
  String role = 'member',
}) => {
  'staff': {
    'public_id': publicId,
    'employee_number': 'EMP-1001',
    'display_name': displayName,
  },
  'role': role,
  'created_at': '2026-09-01T00:00:00+00:00',
  'updated_at': '2026-09-01T00:00:00+00:00',
};

Map<String, dynamic> milestoneJson({
  String publicId = '01J0MILE000000000000000AAA',
  String title = 'Design sign-off',
  String dueDate = '2026-10-20',
  String status = 'pending',
}) => {
  'public_id': publicId,
  'title': title,
  'due_date': dueDate,
  'status': status,
  'project': {
    'public_id': '01J0PROJ000000000000000AAA',
    'name': 'Boiler Upgrade',
  },
  'created_at': '2026-09-01T00:00:00+00:00',
  'updated_at': '2026-09-01T00:00:00+00:00',
};

Map<String, dynamic> clientJson({
  String publicId = '01J0CLNT000000000000000AAA',
  String name = 'Acme Corp',
  String status = 'active',
  Object? clientCode = 'CL-001',
  Object? email = 'office@acme.test',
  Object? phone = '+63 2 8000 1234',
  Object? website = 'https://acme.test',
  Object? addressLine1 = '12 Harbor Road',
  Object? addressLine2,
  Object? city = 'Makati',
  Object? stateProvince = 'Metro Manila',
  Object? postalCode = '1200',
  Object? country = 'Philippines',
}) => {
  'public_id': publicId,
  'client_code': clientCode,
  'name': name,
  'status': status,
  'email': email,
  'phone': phone,
  'website': website,
  'address_line1': addressLine1,
  'address_line2': addressLine2,
  'city': city,
  'state_province': stateProvince,
  'postal_code': postalCode,
  'country': country,
  'notes': 'Internal: slow payer.',
  'contacts_count': 2,
  'created_at': '2026-09-01T00:00:00+00:00',
  'updated_at': '2026-09-01T00:00:00+00:00',
};

Map<String, dynamic> contactJson({
  String publicId = '01J0CONT000000000000000AAA',
  String firstName = 'Maria',
  String lastName = 'Santos',
  bool isPrimary = false,
  String status = 'active',
  Object? jobTitle = 'Facilities Manager',
  Object? email = 'maria@acme.test',
  Object? phone = '+63 917 000 0000',
}) => {
  'public_id': publicId,
  'first_name': firstName,
  'last_name': lastName,
  'full_name': '$firstName $lastName',
  'job_title': jobTitle,
  'email': email,
  'phone': phone,
  'is_primary': isPrimary,
  'status': status,
  'notes': 'Internal: prefers calls.',
  'client': {'public_id': '01J0CLNT000000000000000AAA', 'name': 'Acme Corp'},
  'created_at': '2026-09-01T00:00:00+00:00',
  'updated_at': '2026-09-01T00:00:00+00:00',
};

/// One page in Laravel's paginator shape.
Map<String, dynamic> pageJson(
  List<Map<String, dynamic>> items, {
  int page = 1,
  int lastPage = 1,
  int perPage = 25,
  int? total,
}) => {
  'data': items,
  'links': {'first': null, 'last': null, 'prev': null, 'next': null},
  'meta': {
    'current_page': page,
    'last_page': lastPage,
    'per_page': perPage,
    'total': total ?? items.length,
  },
};

/// `count` distinct projects named `<prefix> 0`, `<prefix> 1`, …
List<Map<String, dynamic>> projectList(int count, {String prefix = 'P'}) => [
  for (var i = 0; i < count; i++)
    projectJson(
      publicId:
          '01J0${prefix.toUpperCase().padRight(8, 'X')}${'$i'.padLeft(14, '0')}',
      name: '$prefix $i',
    ),
];

/// `count` distinct clients named `<prefix> 0`, `<prefix> 1`, …
List<Map<String, dynamic>> clientList(int count, {String prefix = 'C'}) => [
  for (var i = 0; i < count; i++)
    clientJson(
      publicId:
          '01J0${prefix.toUpperCase().padRight(8, 'X')}${'$i'.padLeft(14, '0')}',
      name: '$prefix $i',
    ),
];

ApiClient _api(FakeBackend backend, AuthController session) => ApiClient(
  session: session,
  httpClient: backend.client,
  baseUrl: testBaseUrl,
);

ProjectsApiClient projectsClientFor(
  FakeBackend backend,
  AuthController session,
) => ProjectsApiClient(_api(backend, session));

ClientsApiClient clientsClientFor(
  FakeBackend backend,
  AuthController session,
) => ClientsApiClient(_api(backend, session));

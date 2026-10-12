import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/reports/data/photo_source.dart';
import 'package:mobile/features/reports/data/reports_api_client.dart';

import 'fake_backend.dart';
import 'leave_fixtures.dart' show pageBody;
import 'people_fixtures.dart' show jsonResponse;

/// Payloads in the exact Phase 18/19 shapes with the Phase 33 Gate 1
/// `meta.company_day`. The company day is Wed 14 Oct 2026 in Manila.

const reportMe = 'S-ME';

Map<String, dynamic> rPerson(String id, String name) => {
  'public_id': id,
  'employee_number': 'EMP-$id',
  'display_name': name,
};

Map<String, dynamic> attachmentJson(
  String id, {
  String filename = 'photo.jpg',
  String mime = 'image/jpeg',
  int size = 204800,
}) => {
  'public_id': id,
  'original_filename': filename,
  'mime_type': mime,
  'size_bytes': size,
  'uploaded_by': rPerson(reportMe, 'Ada Lovelace'),
  'created_at': '2026-10-14T02:00:00+00:00',
};

Map<String, dynamic> serviceReportJson(
  String id, {
  String status = 'draft',
  String creator = reportMe,
  String date = '2026-10-13',
  String work = 'Replaced the filter.',
  List<Map<String, dynamic>>? history,
  List<Map<String, dynamic>>? attachments,
  Map<String, dynamic>? project,
}) => {
  'public_id': id,
  'client': {'public_id': 'CL1', 'name': 'Acme Foods'},
  'project': project,
  'task': null,
  'creator': rPerson(
    creator,
    creator == reportMe ? 'Ada Lovelace' : 'Grace Hopper',
  ),
  'participants': <Object>[],
  'service_date': date,
  'work_performed': work,
  'findings': 'Worn belt.',
  'recommendations': null,
  'follow_up_actions': null,
  'site_representative_name': 'Juan',
  'status': status,
  'history': history ?? <Object>[],
  'attachments': attachments ?? <Object>[],
  'created_by': null,
  'created_at': '2026-10-13T02:00:00+00:00',
  'updated_at': '2026-10-13T02:00:00+00:00',
};

Map<String, dynamic> incidentJson(
  String id, {
  String status = 'reported',
  String reporter = reportMe,
  String type = 'workplace_safety',
  String severity = 'high',
  String occurredAt = '2026-10-14T01:30:00+00:00',
  List<Map<String, dynamic>>? attachments,
}) => {
  'public_id': id,
  'client': null,
  'project': null,
  'task': null,
  'reporter': rPerson(
    reporter,
    reporter == reportMe ? 'Ada Lovelace' : 'Grace Hopper',
  ),
  'assigned_to': null,
  'participants': <Object>[],
  'occurred_at': occurredAt,
  'location': 'Loading bay',
  'incident_type': type,
  'severity': severity,
  'description': 'Wet floor, a near slip.',
  'immediate_action_taken': 'Put up a sign.',
  'root_cause': null,
  'corrective_action': null,
  'preventive_action': null,
  'follow_up_actions': null,
  'resolution': null,
  'people_involved': null,
  'witness_notes': null,
  'status': status,
  'history': [
    {
      'action': 'reported',
      'acted_by': rPerson(reportMe, 'Ada Lovelace'),
      'note': null,
      'created_at': occurredAt,
    },
  ],
  'attachments': attachments ?? <Object>[],
  'created_by': null,
  'created_at': occurredAt,
  'updated_at': occurredAt,
};

Map<String, dynamic> projectJson(
  String id,
  String name, {
  String clientId = 'CL1',
  String status = 'active',
}) => {
  'public_id': id,
  'name': name,
  'project_code': null,
  'description': null,
  'status': status,
  'client': {
    'public_id': clientId,
    'name': clientId == 'CL1' ? 'Acme Foods' : 'Other',
  },
  'start_date': null,
  'target_end_date': null,
  'actual_end_date': null,
  'created_at': '2026-01-01T00:00:00+00:00',
  'updated_at': '2026-01-01T00:00:00+00:00',
};

/// A scriptable reports API. Records every request.
class FakeReportsServer {
  String? myStaffId = reportMe;
  List<Map<String, dynamic>> services = [];
  List<Map<String, dynamic>> incidents = [];
  List<Map<String, dynamic>> projects = [];
  Map<String, Uint8List> files = {};
  int uploads = 0;
  int created = 0;

  FutureOr<http.Response?> Function(http.Request request)? override;
  final List<http.Request> requests = [];

  List<http.Request> writes() => [
    for (final r in requests)
      if (r.method != 'GET') r,
  ];

  Map<String, dynamic> bodyOf(http.Request r) =>
      r.body.isEmpty ? {} : jsonDecode(r.body) as Map<String, dynamic>;

  Future<http.Response> handle(http.Request r) async {
    requests.add(r);
    final custom = await override?.call(r);
    if (custom != null) {
      return custom;
    }
    final path = r.url.path.replaceFirst('/api/v1', '');
    final q = r.url.queryParameters;
    final perPage = int.parse(q['per_page'] ?? '50');
    final page = int.parse(q['page'] ?? '1');

    Map<String, dynamic> paged(List<Map<String, dynamic>> rows) => pageBody(
      rows.skip((page - 1) * perPage).take(perPage).toList(),
      page: page,
      perPage: perPage,
      total: rows.length,
    );

    if (path == '/me/profile') {
      return jsonResponse({
        'data': {
          'user': {
            'public_id': 'U1',
            'name': 'Ada',
            'email': 'a@x',
            'status': 'active',
          },
          'staff': myStaffId == null ? null : {'public_id': myStaffId},
        },
      });
    }
    if (path == '/projects') {
      return jsonResponse(paged(projects));
    }

    for (final (base, rows, make) in [
      (
        '/service-reports',
        services,
        (Map<String, dynamic> b) => serviceReportJson(
          'SR-NEW-${++created}',
          date: b['service_date'] as String,
          work: b['work_performed'] as String,
        ),
      ),
      (
        '/incident-reports',
        incidents,
        (Map<String, dynamic> b) => incidentJson(
          'IR-NEW-${++created}',
          type: b['incident_type'] as String,
          severity: b['severity'] as String,
        ),
      ),
    ]) {
      if (path == base && r.method == 'GET') {
        if (myStaffId == null) {
          return jsonError(403, 'No staff record is linked to this account.');
        }

        return jsonResponse(paged(rows));
      }
      if (path == base && r.method == 'POST') {
        final row = make(bodyOf(r));
        rows.insert(0, row);

        return http.Response.bytes(
          utf8.encode(jsonEncode({'data': row})),
          201,
          headers: {'content-type': 'application/json; charset=utf-8'},
        );
      }
      final m = RegExp(
        '^$base/([^/]+)(?:/(submit|return-to-draft|attachments)(?:/([^/]+)(/download)?)?)?\$',
      ).firstMatch(path);
      if (m == null) {
        continue;
      }
      final row = rows.firstWhere(
        (x) => x['public_id'] == m.group(1),
        orElse: () => const {},
      );
      if (row.isEmpty) {
        return jsonError(404, 'Not found.');
      }
      final action = m.group(2);
      if (action == null) {
        switch (r.method) {
          case 'GET':
            return jsonResponse({'data': row});
          case 'PATCH':
            final b = bodyOf(r);
            if (b['service_date'] != null) {
              row['service_date'] = b['service_date'];
            }
            if (b['work_performed'] != null) {
              row['work_performed'] = b['work_performed'];
            }
            if (b['description'] != null) {
              row['description'] = b['description'];
            }

            return jsonResponse({'data': row});
          case 'DELETE':
            rows.remove(row);

            return http.Response('', 204);
        }
      }
      if (action == 'submit') {
        row['status'] = 'submitted';
        (row['history'] as List).add({
          'action': 'submitted',
          'acted_by': rPerson(reportMe, 'Ada Lovelace'),
          'note': null,
          'created_at': '2026-10-14T03:00:00+00:00',
        });

        return jsonResponse({'data': row});
      }
      if (action == 'return-to-draft') {
        row['status'] = 'draft';
        (row['history'] as List).add({
          'action': 'returned_to_draft',
          'acted_by': rPerson(reportMe, 'Ada Lovelace'),
          'note': null,
          'created_at': '2026-10-14T03:00:00+00:00',
        });

        return jsonResponse({'data': row});
      }
      // Attachments.
      final attachments = row['attachments'] as List;
      final attachmentId = m.group(3);
      if (attachmentId == null && r.method == 'POST') {
        uploads++;
        final raw = latin1.decode(r.bodyBytes);
        final name =
            RegExp(r'filename="([^"]+)"').firstMatch(raw)?.group(1) ?? 'file';
        final a = attachmentJson(
          'AT-$uploads',
          filename: name,
          size: r.bodyBytes.length,
        );
        attachments.add(a);
        files['AT-$uploads'] = Uint8List.fromList(r.bodyBytes);

        return http.Response.bytes(
          utf8.encode(jsonEncode({'data': a})),
          201,
          headers: {'content-type': 'application/json; charset=utf-8'},
        );
      }
      if (attachmentId != null && m.group(4) != null) {
        return http.Response.bytes(
          files[attachmentId] ?? _png,
          200,
          headers: {'content-type': 'image/png'},
        );
      }
      if (attachmentId != null && r.method == 'DELETE') {
        attachments.removeWhere((a) => (a as Map)['public_id'] == attachmentId);

        return http.Response('', 204);
      }
    }

    return jsonError(404, 'Unexpected ${r.method} $path');
  }
}

/// A 1×1 transparent PNG.
final Uint8List _png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
);

Uint8List get tinyPng => _png;

/// A scriptable photo source: answers each pick in turn.
class FakePhotoSource implements PhotoSource {
  List<Object?> answers = [];
  final List<PhotoOrigin> picks = [];

  @override
  Future<PickedPhoto?> pick(PhotoOrigin origin) async {
    picks.add(origin);
    final answer = answers.isEmpty ? null : answers.removeAt(0);
    if (answer is Exception) {
      throw answer;
    }

    return answer as PickedPhoto?;
  }
}

ApiClient _api(FakeBackend b, AuthController s) =>
    ApiClient(session: s, httpClient: b.client, baseUrl: testBaseUrl);

ReportsApiClient reportsClientFor(FakeBackend b, AuthController s) =>
    ReportsApiClient(_api(b, s));

ProjectsApiClient projectsClientForReports(FakeBackend b, AuthController s) =>
    ProjectsApiClient(_api(b, s));

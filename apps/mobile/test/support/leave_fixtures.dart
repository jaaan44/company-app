import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/leave/data/leave_api_client.dart';

import 'fake_backend.dart';
import 'my_status_fixtures.dart' show manilaCompanyDay;
import 'people_fixtures.dart' show jsonResponse;

/// Payloads in the exact Phase 13 `LeaveRequestResource` and
/// `LeaveBalanceResource` shapes, with the Phase 31 Gate 1
/// `meta.company_day` and team meta. The company day is 2026-10-14.

Map<String, dynamic> person(String id, String name) => {
  'public_id': id,
  'employee_number': 'EMP-$id',
  'display_name': name,
};

const annualType = {
  'public_id': '01J0TYPEANNUAL000000000AAA',
  'name': 'Annual Leave',
  'code': 'AL',
  'is_paid': true,
};

const unpaidType = {
  'public_id': '01J0TYPEUNPAID000000000AAA',
  'name': 'Unpaid Leave',
  'code': null,
  'is_paid': false,
};

Map<String, dynamic> historyJson(
  String action, {
  Map<String, dynamic>? by,
  String? note,
  String createdAt = '2026-10-10T01:00:00+00:00',
}) => {'action': action, 'acted_by': by, 'note': note, 'created_at': createdAt};

Map<String, dynamic> leaveJson({
  String publicId = '01J0LEAVE0000000000000AAAA',
  Map<String, dynamic>? staff,
  Map<String, dynamic> type = annualType,
  String start = '2026-10-20',
  String end = '2026-10-22',
  int? days,
  String reason = 'Family trip.',
  String status = 'pending',
  List<Map<String, dynamic>>? history,
}) => {
  'public_id': publicId,
  'staff': staff ?? person('01J0STAFFME00000000000AAAA', 'Ada Lovelace'),
  'leave_type': type,
  'start_date': start,
  'end_date': end,
  'total_days':
      days ?? DateTime.parse(end).difference(DateTime.parse(start)).inDays + 1,
  'reason': reason,
  'status': status,
  'history':
      history ??
      [
        historyJson(
          'submitted',
          by: person('01J0STAFFME00000000000AAAA', 'Ada Lovelace'),
        ),
      ],
  'created_by': null,
  'created_at': '2026-10-10T01:00:00+00:00',
  'updated_at': '2026-10-10T01:00:00+00:00',
};

Map<String, dynamic> balanceJson({
  Map<String, dynamic> type = annualType,
  int year = 2026,
  int? allocated = 15,
  int used = 3,
  int pending = 2,
  String? notes,
}) {
  final paid = type['is_paid'] as bool;

  return {
    'leave_type': {
      'public_id': type['public_id'],
      'name': type['name'],
      'code': type['code'],
    },
    'year': year,
    'is_paid': paid,
    'allocated_days': paid ? allocated : null,
    'used_days': used,
    'pending_days': pending,
    'remaining_days': paid ? allocated! - used - pending : null,
    'notes': notes,
  };
}

Map<String, dynamic> pageBody(
  List<Map<String, dynamic>> items, {
  int page = 1,
  int perPage = 20,
  int? total,
  Map<String, dynamic> extraMeta = const {},
}) {
  final count = total ?? items.length;
  final last = count == 0 ? 1 : (count / perPage).ceil();

  return {
    'data': items,
    'links': {'first': null, 'last': null, 'prev': null, 'next': null},
    'meta': {
      'current_page': page,
      'last_page': last,
      'per_page': perPage,
      'total': count,
      'company_day': manilaCompanyDay,
      ...extraMeta,
    },
  };
}

/// A scriptable leave API: my requests, my balances, my team's requests,
/// and the writes — each write answered by [onWrite] (default: the
/// request with its new status). Records every request.
class FakeLeaveServer {
  List<Map<String, dynamic>> mine = [leaveJson()];
  List<Map<String, dynamic>> balances = [
    balanceJson(),
    balanceJson(type: unpaidType),
  ];
  List<Map<String, dynamic>> team = [];
  bool canReview = false;
  int directReports = 0;

  /// When set, answers instead of the defaults (null = fall through).
  FutureOr<http.Response?> Function(http.Request request)? override;

  final List<http.Request> requests = [];

  List<http.Request> writes() => [
    for (final r in requests)
      if (r.method != 'GET') r,
  ];

  Map<String, dynamic> bodyOf(http.Request r) =>
      jsonDecode(r.body) as Map<String, dynamic>;

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

    List<Map<String, dynamic>> slice(List<Map<String, dynamic>> rows) =>
        rows.skip((page - 1) * perPage).take(perPage).toList();

    if (r.method == 'GET' && path == '/me/leave-balances') {
      return jsonResponse({'data': balances});
    }
    if (r.method == 'GET' && path == '/me/leave-requests') {
      return jsonResponse(
        pageBody(slice(mine), page: page, perPage: perPage, total: mine.length),
      );
    }
    if (r.method == 'GET' && path == '/me/team-leave-requests') {
      final status = q['status'] ?? 'pending';
      final rows = !canReview
          ? <Map<String, dynamic>>[]
          : [
              for (final t in team)
                if (status == 'all' || t['status'] == status) t,
            ];

      return jsonResponse(
        pageBody(
          slice(rows),
          page: page,
          perPage: perPage,
          total: rows.length,
          extraMeta: {
            'can_review': canReview,
            'direct_reports_count': canReview ? directReports : 0,
            'pending_count': canReview
                ? team.where((t) => t['status'] == 'pending').length
                : 0,
          },
        ),
      );
    }

    final mineMatch = RegExp(r'^/me/leave-requests/([^/]+)(/cancel)?$')
        .firstMatch(path);
    if (mineMatch != null) {
      final row = mine.firstWhere(
        (m) => m['public_id'] == mineMatch.group(1),
        orElse: () => const {},
      );
      if (row.isEmpty) {
        return jsonError(404, 'Not found.');
      }
      if (mineMatch.group(2) != null) {
        row['status'] = 'cancelled';
        (row['history'] as List).add(
          historyJson('cancelled', note: bodyOf(r)['reason'] as String?),
        );
      }

      return jsonResponse({'data': row});
    }

    final teamMatch = RegExp(r'^/leave-requests/([^/]+)(/approve|/reject)?$')
        .firstMatch(path);
    if (teamMatch != null) {
      final row = team.firstWhere(
        (m) => m['public_id'] == teamMatch.group(1),
        orElse: () => const {},
      );
      if (row.isEmpty) {
        return jsonError(404, 'Not found.');
      }
      final action = teamMatch.group(2);
      if (action != null) {
        final body = bodyOf(r);
        final approved = action == '/approve';
        row['status'] = approved ? 'approved' : 'rejected';
        (row['history'] as List).add(
          historyJson(
            approved ? 'approved' : 'rejected',
            note: (approved ? body['note'] : body['reason']) as String?,
          ),
        );
      }

      return jsonResponse({'data': row});
    }

    if (r.method == 'POST' && path == '/me/leave-requests') {
      final body = bodyOf(r);
      final created = leaveJson(
        publicId: '01J0LEAVENEW00000000000AAA',
        start: body['start_date'] as String,
        end: body['end_date'] as String,
        reason: body['reason'] as String,
      );
      mine.insert(0, created);

      return http.Response.bytes(
        utf8.encode(jsonEncode({'data': created})),
        201,
        headers: {'content-type': 'application/json; charset=utf-8'},
      );
    }

    return jsonError(404, 'Unexpected ${r.method} $path');
  }
}

LeaveApiClient leaveClientFor(FakeBackend backend, AuthController session) =>
    LeaveApiClient(
      ApiClient(
        session: session,
        httpClient: backend.client,
        baseUrl: testBaseUrl,
      ),
    );

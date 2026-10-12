import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/announcements/data/announcements_api_client.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/messages/data/messages_api_client.dart';
import 'package:mobile/features/notifications/data/notifications_api_client.dart';

import 'fake_backend.dart';
import 'leave_fixtures.dart' show pageBody;
import 'people_fixtures.dart' show jsonResponse;

/// Payloads in the exact Phase 14–16 shapes with the Phase 32 Gate 1
/// additions (`meta.company_day`, `last_message`, the read response). The
/// company day is Wed 14 Oct 2026 in Manila.

const meId = 'S-ME';

Map<String, dynamic> chatPerson(String id, String name) => {
  'public_id': id,
  'name': name,
};

Map<String, dynamic> messageJson(
  String id,
  String body, {
  String senderId = 'S-GRACE',
  String senderName = 'Grace Hopper',
  String createdAt = '2026-10-14T01:00:00+00:00',
}) => {
  'public_id': id,
  'body': body,
  'sender': chatPerson(senderId, senderName),
  'created_at': createdAt,
};

Map<String, dynamic> announcementJson(
  String id, {
  String title = 'Office closed Friday',
  String body = 'The office is closed on Friday.\nEnjoy the long weekend.',
  String publishedAt = '2026-10-13T02:00:00+00:00',
  String? acknowledgedAt,
}) => {
  'public_id': id,
  'title': title,
  'body': body,
  'status': 'published',
  'audience_type': 'company_wide',
  'departments': <Object>[],
  'teams': <Object>[],
  'published_at': publishedAt,
  'created_by': null,
  'published_by': {
    'public_id': 'S-ADMIN',
    'employee_number': 'EMP-1',
    'display_name': 'Ada Admin',
  },
  'acknowledged_at': acknowledgedAt,
  'created_at': publishedAt,
  'updated_at': publishedAt,
};

Map<String, dynamic> notificationJson(
  String id, {
  String type = 'announcement_published',
  String title = 'Office closed Friday',
  String message = 'A new company announcement has been published.',
  String? sourceType = 'announcement',
  String? sourceId = 'A1',
  String? readAt,
  String createdAt = '2026-10-14T00:30:00+00:00',
}) => {
  'public_id': id,
  'type': type,
  'title': title,
  'message': message,
  'source': sourceType == null
      ? null
      : {'type': sourceType, 'public_id': sourceId},
  'read_at': readAt,
  'created_at': createdAt,
};

/// One in-memory conversation.
class FakeChat {
  FakeChat(
    this.id, {
    this.type = 'direct',
    this.name,
    this.projectName,
    List<Map<String, dynamic>>? members,
    List<Map<String, dynamic>>? messages,
    this.unread = 0,
  }) : members =
           members ??
           [
             chatPerson(meId, 'Ada Lovelace'),
             chatPerson('S-GRACE', 'Grace Hopper'),
           ],
       messages = messages ?? [];

  final String id;
  final String type;
  final String? name;
  final String? projectName;
  final List<Map<String, dynamic>> members;

  /// Oldest first.
  final List<Map<String, dynamic>> messages;
  int unread;

  Map<String, dynamic> toJson() => {
    'public_id': id,
    'type': type,
    'name': name,
    if (projectName != null)
      'project': {'public_id': 'P-$id', 'name': projectName},
    'members': [
      for (final m in members) {'staff': m},
    ],
    'unread_count': unread,
    'last_message': messages.isEmpty ? null : messages.last,
    'created_at': '2026-10-01T00:00:00+00:00',
  };
}

/// A scriptable communication API. Records every request.
class FakeInboxServer {
  String? myStaffId = meId;
  List<FakeChat> chats = [];
  List<Map<String, dynamic>> notifications = [];
  List<Map<String, dynamic>> announcements = [];
  bool profileForbidden = false;

  /// When set, answers instead of the defaults (null = fall through).
  FutureOr<http.Response?> Function(http.Request request)? override;

  final List<http.Request> requests = [];
  int sentCount = 0;

  List<http.Request> writes() => [
    for (final r in requests)
      if (r.method != 'GET') r,
  ];

  Map<String, dynamic> bodyOf(http.Request r) =>
      r.body.isEmpty ? {} : jsonDecode(r.body) as Map<String, dynamic>;

  FakeChat? chat(String id) {
    for (final c in chats) {
      if (c.id == id) {
        return c;
      }
    }

    return null;
  }

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

    // Conversations.
    if (path == '/conversations' && r.method == 'GET') {
      if (myStaffId == null) {
        return jsonError(403, 'No staff record is linked to this account.');
      }

      return jsonResponse(paged([for (final c in chats) c.toJson()]));
    }
    if (path == '/conversations/direct' && r.method == 'POST') {
      final target = bodyOf(r)['staff_id'] as String;
      final existing = chats.where(
        (c) =>
            c.type == 'direct' &&
            c.members.any((m) => m['public_id'] == target),
      );
      final c = existing.isNotEmpty
          ? existing.first
          : (FakeChat(
              'C-NEW-$target',
              members: [
                chatPerson(meId, 'Ada Lovelace'),
                chatPerson(target, 'Person $target'),
              ],
            ));
      if (existing.isEmpty) {
        chats.insert(0, c);
      }

      return jsonResponse({'data': c.toJson()});
    }
    final chatMatch = RegExp(r'^/conversations/([^/]+)(/messages|/read)?$')
        .firstMatch(path);
    if (chatMatch != null) {
      final c = chat(chatMatch.group(1)!);
      if (c == null) {
        return jsonError(404, 'Not found.');
      }
      switch ((r.method, chatMatch.group(2))) {
        case ('GET', null):
          return jsonResponse({'data': c.toJson()});
        case ('GET', '/messages'):
          return jsonResponse(paged(c.messages.reversed.toList()));
        case ('POST', '/messages'):
          sentCount++;
          final m = messageJson(
            'M-SENT-$sentCount',
            (bodyOf(r)['body'] as String),
            senderId: meId,
            senderName: 'Ada Lovelace',
            createdAt: '2026-10-14T05:0$sentCount:00+00:00',
          );
          c.messages.add(m);
          chats
            ..remove(c)
            ..insert(0, c);

          return http.Response.bytes(
            utf8.encode(jsonEncode({'data': m})),
            201,
            headers: {'content-type': 'application/json; charset=utf-8'},
          );
        case ('POST', '/read'):
          c.unread = 0;
          var marked = 0;
          for (final n in notifications) {
            final s = n['source'] as Map<String, dynamic>?;
            if (n['read_at'] == null && s?['public_id'] == c.id) {
              n['read_at'] = '2026-10-14T06:00:00+00:00';
              marked++;
            }
          }

          return jsonResponse({
            'data': {'unread_count': 0, 'notifications_marked_read': marked},
          });
      }
    }

    // Notifications.
    if (path == '/me/notifications' && r.method == 'GET') {
      return jsonResponse(paged(notifications));
    }
    if (path == '/me/notifications/read-all') {
      var n = 0;
      for (final row in notifications) {
        if (row['read_at'] == null) {
          row['read_at'] = '2026-10-14T06:00:00+00:00';
          n++;
        }
      }

      return jsonResponse({
        'data': {'updated_count': n},
      });
    }
    final readMatch = RegExp(r'^/me/notifications/([^/]+)/read$')
        .firstMatch(path);
    if (readMatch != null) {
      final row = notifications.firstWhere(
        (n) => n['public_id'] == readMatch.group(1),
        orElse: () => const {},
      );
      if (row.isEmpty) {
        return jsonError(404, 'Not found.');
      }
      row['read_at'] ??= '2026-10-14T06:00:00+00:00';

      return jsonResponse({'data': row});
    }
    if (path == '/me/notifications/unread-count') {
      return jsonResponse({
        'data': {
          'unread_count': notifications
              .where((n) => n['read_at'] == null)
              .length,
        },
      });
    }

    // Announcements.
    if (path == '/me/announcements' && r.method == 'GET') {
      if (myStaffId == null) {
        return jsonError(403, 'No staff record is linked to this account.');
      }

      return jsonResponse(paged(announcements));
    }
    final annMatch = RegExp(r'^/me/announcements/([^/]+)(/acknowledge)?$')
        .firstMatch(path);
    if (annMatch != null) {
      final row = announcements.firstWhere(
        (a) => a['public_id'] == annMatch.group(1),
        orElse: () => const {},
      );
      if (row.isEmpty) {
        return jsonError(404, 'Not found.');
      }
      if (annMatch.group(2) != null) {
        row['acknowledged_at'] ??= '2026-10-14T06:00:00+00:00';
      }

      return jsonResponse({'data': row});
    }

    return jsonError(404, 'Unexpected ${r.method} $path');
  }
}

ApiClient _api(FakeBackend backend, AuthController session) => ApiClient(
  session: session,
  httpClient: backend.client,
  baseUrl: testBaseUrl,
);

MessagesApiClient messagesClientFor(FakeBackend b, AuthController s) =>
    MessagesApiClient(_api(b, s));

NotificationsApiClient notificationsClientFor(
  FakeBackend b,
  AuthController s,
) => NotificationsApiClient(_api(b, s));

AnnouncementsApiClient announcementsClientFor(
  FakeBackend b,
  AuthController s,
) => AnnouncementsApiClient(_api(b, s));

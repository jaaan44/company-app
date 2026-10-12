import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/announcements/domain/announcement.dart';
import 'package:mobile/features/announcements/state/announcements_controllers.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/messages/domain/conversation.dart';
import 'package:mobile/features/messages/state/chat_controller.dart';
import 'package:mobile/features/messages/state/conversations_controller.dart';
import 'package:mobile/features/notifications/domain/app_notification.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/notifications/state/notifications_controller.dart';

import '../../support/fake_backend.dart';
import '../../support/inbox_fixtures.dart';

/// Phase 32 Gate 2 — the communication models, API clients and
/// controllers over the real `ApiClient` (spec R-2…R-11).
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late FakeInboxServer server;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    server = FakeInboxServer();
    backend.onApi = server.handle;
  });

  String target(http.Request r) =>
      '${r.method} ${r.url.path.replaceFirst('/api/v1', '')}'
      '${r.url.hasQuery ? '?${r.url.query}' : ''}';

  group('models', () {
    test('a conversation is named for me', () {
      final direct = Conversation.fromJson(FakeChat('C1').toJson());
      expect(direct.titleFor(meId), 'Grace Hopper');
      expect(direct.showsSenderNames, isFalse);
      expect(direct.lastMessage, isNull);

      final group = Conversation.fromJson(
        FakeChat('C2', type: 'group', name: 'Site team').toJson(),
      );
      expect(group.titleFor(meId), 'Site team');
      expect(group.showsSenderNames, isTrue);

      final project = Conversation.fromJson(
        FakeChat('C3', type: 'project', projectName: 'Plant Room').toJson(),
      );
      expect(project.titleFor(meId), 'Plant Room');

      final odd = Conversation.fromJson(
        FakeChat('C4', type: 'broadcast').toJson(),
      );
      expect(odd.kind, ConversationKind.unknown);
    });

    test('the last message parses', () {
      final c = Conversation.fromJson(
        FakeChat('C1', messages: [messageJson('M1', 'Hi')], unread: 3).toJson(),
      );

      expect(c.lastMessage?.body, 'Hi');
      expect(c.lastMessage?.sender.name, 'Grace Hopper');
      expect(c.lastMessage?.createdAt, DateTime.utc(2026, 10, 14, 1));
      expect(c.unreadCount, 3);
    });

    test('broken shapes are rejected', () {
      final json = FakeChat('C1').toJson()..remove('members');
      expect(() => Conversation.fromJson(json), throwsFormatException);
      expect(
        () => ChatMessage.fromJson(messageJson('M', 'x')..remove('sender')),
        throwsFormatException,
      );
      expect(
        () => AppNotification.fromJson(
          notificationJson('N')..['created_at'] = 'soon',
        ),
        throwsFormatException,
      );
      expect(
        () => Announcement.fromJson(announcementJson('A')..remove('body')),
        throwsFormatException,
      );
    });

    test('notifications: sources, unknown kinds, read state', () {
      final n = AppNotification.fromJson(notificationJson('N1'));
      expect(n.source?.kind, NotificationSourceKind.announcement);
      expect(n.isUnread, isTrue);
      expect(n.markedRead(DateTime.utc(2026)).isUnread, isFalse);

      final odd = AppNotification.fromJson(
        notificationJson('N2', sourceType: 'task', sourceId: 'T1'),
      );
      expect(odd.source?.kind, NotificationSourceKind.unknown);
      expect(
        AppNotification.fromJson(notificationJson('N3', sourceType: null))
            .source,
        isNull,
      );
    });

    test('announcements: publisher and acknowledgement', () {
      final a = Announcement.fromJson(
        announcementJson('A1', acknowledgedAt: '2026-10-14T03:00:00+00:00'),
      );
      expect(a.publishedBy, 'Ada Admin');
      expect(a.isAcknowledged, isTrue);
      expect(a.body, contains('\n'));
    });
  });

  group('ConversationsController', () {
    test('learns my id once and lists my conversations', () async {
      server.chats = [
        FakeChat('C1', messages: [messageJson('M1', 'Hi')], unread: 1),
        FakeChat('C2', type: 'group', name: 'Site team'),
      ];
      final c = ConversationsController(messagesClientFor(backend, auth));

      await c.load();
      await c.refresh();

      expect(c.status, PagedListStatus.loaded);
      expect(c.myStaffId, meId);
      expect(c.companyDay?.date, '2026-10-14');
      expect(c.items.map((i) => i.publicId), ['C1', 'C2']);
      expect(server.requests.map(target), [
        'GET /me/profile',
        'GET /conversations?per_page=20&page=1',
        'GET /conversations?per_page=20&page=1',
      ]);
    });

    test('no profile: from the profile or a 403', () async {
      server.myStaffId = null;
      final c = ConversationsController(messagesClientFor(backend, auth));
      await c.load();
      expect(c.status, PagedListStatus.noProfile);
      expect(
        server.requests.where((r) => r.url.path.endsWith('/conversations')),
        isEmpty,
      );

      server.myStaffId = meId;
      server.override = (r) =>
          r.url.path.endsWith('/conversations') ? jsonError(403) : null;
      final d = ConversationsController(messagesClientFor(backend, auth));
      await d.load();
      expect(d.status, PagedListStatus.noProfile);
    });
  });

  group('ChatController', () {
    List<Map<String, dynamic>> history(int n) => [
      for (var i = 0; i < n; i++)
        messageJson(
          'M${i.toString().padLeft(3, '0')}',
          'Message $i',
          createdAt: DateTime.utc(2026, 10, 13, 0, i).toIso8601String(),
        ),
    ];

    ChatController chatFor(FakeChat chat, {InboxChanges? changes}) {
      server.chats = [chat];

      return ChatController(
        messagesClientFor(backend, auth),
        chat.id,
        changes: changes,
      );
    }

    test('opens with the newest page, oldest first, and marks read', () async {
      final changes = InboxChanges();
      final c = chatFor(
        FakeChat('C1', messages: history(45), unread: 2),
        changes: changes,
      );

      await c.load();

      expect(c.status, ChatStatus.loaded);
      expect(c.title, 'Grace Hopper');
      expect(c.messages, hasLength(30));
      expect(c.messages.first.publicId, 'M015');
      expect(c.messages.last.publicId, 'M044');
      expect(c.hasOlder, isTrue);
      expect(server.requests.map(target), [
        'GET /me/profile',
        'GET /conversations/C1',
        'GET /conversations/C1/messages?per_page=30&page=1',
        'POST /conversations/C1/read',
      ]);
      expect(changes.revision, 1);
    });

    test('nothing unread: no read call', () async {
      server.chats = [FakeChat('C1', messages: history(2))];
      final c = ChatController(
        messagesClientFor(backend, auth),
        'C1',
        initial: Conversation.fromJson(server.chats.first.toJson()),
        myStaffId: meId,
      );

      await c.load();

      expect(server.requests.map(target), [
        'GET /conversations/C1/messages?per_page=30&page=1',
      ]);
    });

    test(
      'older pages go on top without repeats, even after new ones',
      () async {
        final chat = FakeChat('C1', messages: history(45));
        final c = chatFor(chat);
        await c.load();

        // Five new messages shift the server's pages.
        chat.messages.addAll([
          for (var i = 0; i < 5; i++)
            messageJson(
              'N$i',
              'New $i',
              createdAt: '2026-10-14T02:0$i:00+00:00',
            ),
        ]);
        await c.loadOlder();

        final ids = c.messages.map((m) => m.publicId).toList();
        expect(ids.toSet(), hasLength(ids.length));
        expect(ids.first, 'M000');
        expect(ids.last, 'M044');
        expect(c.hasOlder, isFalse);
      },
    );

    test('a failed older page can be retried', () async {
      final c = chatFor(FakeChat('C1', messages: history(35)));
      await c.load();
      server.override = (r) =>
          r.url.queryParameters['page'] == '2' ? jsonError(500) : null;

      await c.loadOlder();
      expect((c.loadOlderFailed, c.messages.length), (true, 30));

      server.override = null;
      await c.loadOlder();
      expect((c.loadOlderFailed, c.messages.length), (false, 35));
    });

    test('poll adds new messages at the bottom and reads them', () async {
      final chat = FakeChat('C1', messages: history(3));
      final c = chatFor(chat);
      await c.load();
      expect(await c.poll(), 0);
      expect(server.writes(), isEmpty);

      chat.messages.add(
        messageJson(
          'NEW',
          'Are you there?',
          createdAt: '2026-10-14T02:00:00+00:00',
        ),
      );
      expect(await c.poll(), 1);

      expect(c.messages.last.body, 'Are you there?');
      expect(server.writes().map(target), ['POST /conversations/C1/read']);
    });

    test('poll catches up when a whole page is new', () async {
      final chat = FakeChat('C1', messages: history(2));
      final c = chatFor(chat);
      await c.load();
      chat.messages.addAll([
        for (var i = 0; i < 40; i++)
          messageJson(
            'Z${i.toString().padLeft(2, '0')}',
            'Burst $i',
            createdAt: DateTime.utc(2026, 10, 14, 3, i).toIso8601String(),
          ),
      ]);

      expect(await c.poll(), 40);
      expect(c.messages, hasLength(42));
      expect(c.messages.last.publicId, 'Z39');
      expect(c.messages[2].publicId, 'Z00');
    });

    test('a failed poll is quiet', () async {
      final c = chatFor(FakeChat('C1', messages: history(2)));
      await c.load();
      server.override = (r) => throw const NetworkFailure();

      expect(await c.poll(), 0);
      expect(c.status, ChatStatus.loaded);
    });

    test('send: once, trimmed, added at the bottom', () async {
      final changes = InboxChanges();
      final c = chatFor(FakeChat('C1', messages: history(2)), changes: changes);
      await c.load();

      final first = c.send('  Hello  ');
      expect(await c.send('Again'), isFalse);
      expect(await first, isTrue);

      expect(server.writes().map(server.bodyOf), [
        {'body': 'Hello'},
      ]);
      expect(c.messages.last.body, 'Hello');
      expect(c.isMine(c.messages.last), isTrue);
      expect(changes.revision, 1);
      // A later poll doesn't add it twice.
      await c.poll();
      expect(c.messages.where((m) => m.body == 'Hello'), hasLength(1));
    });

    test('send: blank or too long is not sent', () async {
      final c = chatFor(FakeChat('C1'));
      await c.load();

      expect(await c.send('   '), isFalse);
      expect(await c.send('x' * 4001), isFalse);
      expect(c.sendError, 'A message can be at most 4000 characters.');
      expect(server.writes(), isEmpty);
    });

    test(
      'send offline: "Couldn\'t send. Try again." and nothing added',
      () async {
        final c = chatFor(FakeChat('C1'));
        await c.load();
        server.override = (r) =>
            r.method == 'POST' ? throw const NetworkFailure() : null;

        expect(await c.send('Hello'), isFalse);
        expect(c.sendError, "Couldn't send. Try again.");
        expect(c.messages, isEmpty);
      },
    );

    test('a 404 conversation', () async {
      server.chats = [];
      final c = ChatController(messagesClientFor(backend, auth), 'GONE');

      await c.load();

      expect(c.errorMessage, 'This conversation is no longer available.');
    });

    test('no profile', () async {
      server.myStaffId = null;
      final c = ChatController(messagesClientFor(backend, auth), 'C1');

      await c.load();

      expect(c.status, ChatStatus.noProfile);
    });
  });

  group('NotificationsController', () {
    test('lists, marks one read, marks all read', () async {
      final changes = InboxChanges();
      server.notifications = [
        notificationJson(
          'N2',
          type: 'message_received',
          sourceType: 'conversation',
          sourceId: 'C1',
        ),
        notificationJson('N1'),
      ];
      final c = NotificationsController(
        notificationsClientFor(backend, auth),
        changes: changes,
      );

      await c.load();
      expect(c.items.map((n) => n.publicId), ['N2', 'N1']);
      expect(c.hasUnread, isTrue);

      expect(await c.markRead(c.items.first), isTrue);
      expect(c.items.first.isUnread, isFalse);
      expect(changes.revision, 1);
      // Already read: no request.
      final before = server.requests.length;
      expect(await c.markRead(c.items.first), isTrue);
      expect(server.requests.length, before);

      expect(await c.markAllRead(), isTrue);
      expect(c.hasUnread, isFalse);
      expect(changes.revision, 2);
      expect(server.writes().map(target), [
        'POST /me/notifications/N2/read',
        'POST /me/notifications/read-all',
      ]);
    });

    test('a failed mark-all says so', () async {
      server.notifications = [notificationJson('N1')];
      final c = NotificationsController(notificationsClientFor(backend, auth));
      await c.load();
      server.override = (r) => r.method == 'POST' ? jsonError(500) : null;

      expect(await c.markAllRead(), isFalse);
      expect(c.actionMessage, "Couldn't mark all read. Please try again.");
      expect(c.hasUnread, isTrue);
    });
  });

  group('Announcements', () {
    test('the feed; no profile', () async {
      server.announcements = [announcementJson('A2'), announcementJson('A1')];
      final c = AnnouncementsController(announcementsClientFor(backend, auth));
      await c.load();
      expect(c.items.map((a) => a.publicId), ['A2', 'A1']);
      expect(c.companyDay?.date, '2026-10-14');

      server.myStaffId = null;
      final d = AnnouncementsController(announcementsClientFor(backend, auth));
      await d.load();
      expect(d.status, PagedListStatus.noProfile);
    });

    test('acknowledge once, confirmed', () async {
      final changes = InboxChanges();
      server.announcements = [announcementJson('A1')];
      final c = AnnouncementController(
        announcementsClientFor(backend, auth),
        'A1',
        changes: changes,
      );
      await c.load();
      expect(c.data?.isAcknowledged, isFalse);

      final first = c.acknowledge();
      expect(await c.acknowledge(), isFalse);
      expect(await first, isTrue);

      expect(c.data?.isAcknowledged, isTrue);
      expect(server.writes().map(target), [
        'POST /me/announcements/A1/acknowledge',
      ]);
      expect(changes.revision, 1);
      expect(await c.acknowledge(), isFalse);
    });

    test('acknowledge offline keeps it unacknowledged', () async {
      server.announcements = [announcementJson('A1')];
      final c = AnnouncementController(
        announcementsClientFor(backend, auth),
        'A1',
      );
      await c.load();
      server.override = (r) =>
          r.method == 'POST' ? throw const NetworkFailure() : null;

      expect(await c.acknowledge(), isFalse);
      expect(
        c.actionMessage,
        "Couldn't acknowledge. Check your connection and try again.",
      );
      expect(c.data?.isAcknowledged, isFalse);
    });

    test('a gone announcement', () async {
      final c = AnnouncementController(
        announcementsClientFor(backend, auth),
        'X',
      );
      await c.load();

      expect(c.errorMessage, 'This announcement is no longer available.');
    });

    test('a body that breaks the contract is a request failure', () async {
      server.override = (r) => http.Response('{"data":{"title":1}}', 200);
      final client = announcementsClientFor(backend, auth);

      await expectLater(client.fetch('A'), throwsA(isA<ApiRequestException>()));
    });
  });
}

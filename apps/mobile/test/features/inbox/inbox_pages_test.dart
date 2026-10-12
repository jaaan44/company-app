import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/announcements/presentation/announcements_pages.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/messages/presentation/chat_page.dart';
import 'package:mobile/features/messages/presentation/conversations_page.dart';
import 'package:mobile/features/messages/presentation/new_message_page.dart';
import 'package:mobile/features/notifications/presentation/notifications_page.dart';
import 'package:mobile/features/people/presentation/more_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart' show homeClientFor, homeResponse;
import '../../support/inbox_fixtures.dart';
import '../../support/my_status_fixtures.dart' show myStatusClientFor;
import '../../support/leave_fixtures.dart' show pageBody;
import '../../support/people_fixtures.dart'
    show directoryPageJson, jsonResponse, peopleClientFor, staffMemberJson;

/// Phase 32 Gate 3 — Messages, a chat, New message, Notifications and
/// Announcements through the real app against an in-memory API
/// (docs/phases/V1_PHASE_32_DEFINITION.md, R-3…R-11). The company day is
/// Wed 14 Oct 2026 in Manila.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late FakeInboxServer server;
  late List<http.Request> requests;
  late int homeReads;

  Future<http.Response> route(http.Request r) async {
    requests.add(r);
    final path = r.url.path;
    if (path.endsWith('/me/home')) {
      homeReads++;

      return homeResponse();
    }
    if (path.endsWith('/me/status')) {
      return jsonResponse(pageBody([]));
    }
    if (path.endsWith('/staff')) {
      return jsonResponse(
        directoryPageJson([
          staffMemberJson(
            publicId: meId,
            firstName: 'Ada',
            lastName: 'Lovelace',
          ),
          staffMemberJson(
            publicId: 'S-ALAN',
            firstName: 'Alan',
            lastName: 'Turing',
          ),
          staffMemberJson(
            publicId: 'S-LIN',
            firstName: 'Linus',
            lastName: 'Torvalds',
          ),
          staffMemberJson(
            publicId: 'S-GRACE',
            firstName: 'Grace',
            lastName: 'Hopper',
          ),
        ]),
      );
    }

    return server.handle(r);
  }

  List<Map<String, dynamic>> history(int n, {String day = '2026-10-14'}) => [
    for (var i = 0; i < n; i++)
      messageJson(
        'M${i.toString().padLeft(3, '0')}',
        'Message $i',
        createdAt: '${day}T00:${i.toString().padLeft(2, '0')}:00+00:00',
      ),
  ];

  setUp(() {
    requests = [];
    homeReads = 0;
    server = FakeInboxServer()
      ..chats = [
        FakeChat(
          'C-GRACE',
          unread: 2,
          messages: [
            messageJson(
              'M-OLD',
              'See you Monday',
              createdAt: '2026-10-12T09:00:00+00:00',
            ),
            messageJson(
              'M-Y',
              'Yesterday note',
              createdAt: '2026-10-13T03:00:00+00:00',
            ),
            messageJson(
              'M-T',
              'Are you in today?',
              createdAt: '2026-10-14T01:00:00+00:00',
            ),
          ],
        ),
        FakeChat(
          'C-TEAM',
          type: 'group',
          name: 'Site team',
          members: [
            chatPerson(meId, 'Ada Lovelace'),
            chatPerson('S-ALAN', 'Alan Turing'),
          ],
          messages: [
            messageJson(
              'M-G',
              'Done for today',
              senderId: meId,
              senderName: 'Ada Lovelace',
              createdAt: '2026-10-13T10:00:00+00:00',
            ),
          ],
        ),
        FakeChat(
          'C-EMPTY',
          members: [
            chatPerson(meId, 'Ada Lovelace'),
            chatPerson('S-ALAN', 'Alan Turing'),
          ],
        ),
      ]
      ..notifications = [
        notificationJson(
          'N-MSG',
          type: 'message_received',
          title: 'New message',
          message: 'You have a new message.',
          sourceType: 'conversation',
          sourceId: 'C-GRACE',
          createdAt: '2026-10-14T01:00:00+00:00',
        ),
        notificationJson(
          'N-ANN',
          sourceId: 'A1',
          createdAt: '2026-10-13T02:00:00+00:00',
        ),
        notificationJson(
          'N-OLD',
          title: 'Old news',
          sourceId: 'A2',
          readAt: '2026-10-10T00:00:00+00:00',
          createdAt: '2026-10-09T02:00:00+00:00',
        ),
      ]
      ..announcements = [
        announcementJson('A1'),
        announcementJson(
          'A2',
          title: 'Old news',
          publishedAt: '2026-10-09T02:00:00+00:00',
          acknowledgedAt: '2026-10-10T01:00:00+00:00',
        ),
      ];
  });

  setUpAll(() => WidgetController.hitTestWarningShouldBeFatal = true);
  tearDownAll(() => WidgetController.hitTestWarningShouldBeFatal = false);

  Future<void> pumpApp(WidgetTester tester, {bool tall = true}) async {
    if (tall) {
      tester.view.physicalSize =
          const Size(800, 2400) * tester.view.devicePixelRatio;
      addTearDown(tester.view.resetPhysicalSize);
    }
    backend = FakeBackend()..onApi = route;
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await tester.pumpWidget(
      CompanyApp(
        authController: auth,
        homeApiClient: homeClientFor(backend, auth),
        myStatusApiClient: myStatusClientFor(backend, auth),
        peopleApiClient: peopleClientFor(backend, auth),
        messagesApiClient: messagesClientFor(backend, auth),
        notificationsApiClient: notificationsClientFor(backend, auth),
        announcementsApiClient: announcementsClientFor(backend, auth),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> tapKey(WidgetTester tester, String key) async {
    await tester.ensureVisible(find.byKey(Key(key)));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key(key)));
    await tester.pumpAndSettle();
  }

  Future<void> openTab(WidgetTester tester, String label) async {
    await tester.tap(find.widgetWithText(NavigationDestination, label));
    await tester.pumpAndSettle();
  }

  Future<void> openMoreRow(WidgetTester tester, String key) async {
    await openTab(tester, 'More');
    await tester.scrollUntilVisible(
      find.byKey(Key(key)),
      100,
      scrollable: find
          .descendant(
            of: find.byType(MorePage),
            matching: find.byType(Scrollable),
          )
          .first,
    );
    await tapKey(tester, key);
  }

  Finder inKey(String key, Finder matching) =>
      find.descendant(of: find.byKey(Key(key)), matching: matching);

  String target(http.Request r) =>
      '${r.method} ${r.url.path.replaceFirst('/api/v1', '')}';

  group('Messages tab (R-3)', () {
    testWidgets('my conversations with names, previews, times and badges', (
      tester,
    ) async {
      await pumpApp(tester);
      await openTab(tester, 'Messages');

      expect(find.byType(ConversationsPage), findsOneWidget);
      expect(
        inKey('messages-row-C-GRACE', find.text('Grace Hopper')),
        findsOneWidget,
      );
      expect(
        inKey('messages-row-C-GRACE', find.text('Are you in today?')),
        findsOneWidget,
      );
      // 01:00 UTC is 09:00 in Manila, today.
      expect(inKey('messages-row-C-GRACE', find.text('09:00')), findsOneWidget);
      expect(inKey('messages-row-C-GRACE', find.text('2')), findsOneWidget);
      expect(
        inKey('messages-row-C-TEAM', find.text('Site team')),
        findsOneWidget,
      );
      expect(
        inKey('messages-row-C-TEAM', find.text('You: Done for today')),
        findsOneWidget,
      );
      // 10:00 UTC on 13 Oct is 18:00 that day in Manila: yesterday.
      expect(
        inKey('messages-row-C-TEAM', find.text('Yesterday')),
        findsOneWidget,
      );
      expect(
        inKey('messages-row-C-EMPTY', find.text('Alan Turing')),
        findsOneWidget,
      );
      expect(
        inKey('messages-row-C-EMPTY', find.text('No messages yet')),
        findsOneWidget,
      );
    });

    testWidgets('nothing yet', (tester) async {
      server.chats = [];
      await pumpApp(tester);
      await openTab(tester, 'Messages');

      expect(find.text('No conversations yet.'), findsOneWidget);
    });

    testWidgets('no profile', (tester) async {
      server.myStaffId = null;
      await pumpApp(tester);
      await openTab(tester, 'Messages');

      expect(find.byKey(const Key('messages-no-profile')), findsOneWidget);
    });
  });

  group('A conversation (R-4, R-5)', () {
    testWidgets(
      'opens at the newest with day separators; reading clears the badge and its notifications',
      (tester) async {
        await pumpApp(tester);
        final homeBefore = homeReads;
        await openTab(tester, 'Messages');
        await tapKey(tester, 'messages-row-C-GRACE');

        expect(find.byType(ChatPage), findsOneWidget);
        expect(find.text('Grace Hopper'), findsOneWidget);
        for (final label in ['Today', 'Yesterday', 'Mon 12 Oct']) {
          expect(find.text(label), findsOneWidget, reason: label);
        }
        expect(find.text('Are you in today?'), findsOneWidget);
        expect(find.text('09:00'), findsOneWidget);
        expect(
          requests.map(target),
          contains('POST /conversations/C-GRACE/read'),
        );
        // The message notification was read with the chat (R-2d).
        expect(server.notifications.first['read_at'], isNotNull);
        // Home's counts reload (R-11).
        expect(homeReads, greaterThan(homeBefore));

        await tester.pageBack();
        await tester.pumpAndSettle();
        expect(inKey('messages-row-C-GRACE', find.text('2')), findsNothing);
      },
    );

    testWidgets('send: on the right, once, the composer clears', (
      tester,
    ) async {
      await pumpApp(tester);
      await openTab(tester, 'Messages');
      await tapKey(tester, 'messages-row-C-GRACE');

      await tester.enterText(
        find.byKey(const Key('chat-input')),
        '  On my way  ',
      );
      await tapKey(tester, 'chat-send');

      final posts = requests.where(
        (r) => r.method == 'POST' && r.url.path.endsWith('/messages'),
      );
      expect(posts, hasLength(1));
      expect(server.bodyOf(posts.single), {'body': 'On my way'});
      final bubble = find.byKey(const Key('chat-message-M-SENT-1'));
      expect(bubble, findsOneWidget);
      // Mine is right-aligned: it starts past the middle of the screen.
      expect(
        tester
            .getTopLeft(
              find
                  .descendant(of: bubble, matching: find.byType(Container))
                  .first,
            )
            .dx,
        greaterThan(tester.getSize(find.byType(ChatPage)).width / 2),
      );
      expect(
        tester
            .widget<TextField>(find.byKey(const Key('chat-input')))
            .controller!
            .text,
        isEmpty,
      );
    });

    testWidgets("offline send keeps the text: \"Couldn't send. Try again.\"", (
      tester,
    ) async {
      await pumpApp(tester);
      await openTab(tester, 'Messages');
      await tapKey(tester, 'messages-row-C-GRACE');
      server.override = (r) =>
          r.method == 'POST' && r.url.path.endsWith('/messages')
          ? throw const NetworkFailure()
          : null;

      await tester.enterText(find.byKey(const Key('chat-input')), 'Hello');
      await tapKey(tester, 'chat-send');

      expect(find.text("Couldn't send. Try again."), findsOneWidget);
      expect(find.text('Hello'), findsOneWidget);

      server.override = null;
      await tapKey(tester, 'chat-send');
      expect(find.text("Couldn't send. Try again."), findsNothing);
      expect(find.byKey(const Key('chat-message-M-SENT-1')), findsOneWidget);
    });

    testWidgets('a new message arrives within 15 s while the chat is open', (
      tester,
    ) async {
      await pumpApp(tester);
      await openTab(tester, 'Messages');
      await tapKey(tester, 'messages-row-C-GRACE');

      server.chats.first.messages.add(
        messageJson(
          'M-LIVE',
          'Just landed',
          createdAt: '2026-10-14T02:00:00+00:00',
        ),
      );
      await tester.pump(const Duration(seconds: 14));
      expect(find.text('Just landed'), findsNothing);
      await tester.pump(const Duration(seconds: 2));
      await tester.pumpAndSettle();
      expect(find.text('Just landed'), findsOneWidget);

      // Not polled once the chat is closed.
      await tester.pageBack();
      await tester.pumpAndSettle();
      final reads = requests
          .where((r) => r.url.path.endsWith('/C-GRACE/messages'))
          .length;
      await tester.pump(const Duration(seconds: 31));
      await tester.pumpAndSettle();
      expect(
        requests.where((r) => r.url.path.endsWith('/C-GRACE/messages')).length,
        reads,
      );
    });

    testWidgets('a long chat loads earlier messages', (tester) async {
      server.chats.first.messages
        ..clear()
        ..addAll(history(40));
      await pumpApp(tester);
      await openTab(tester, 'Messages');
      await tapKey(tester, 'messages-row-C-GRACE');

      expect(find.byKey(const Key('chat-message-M000')), findsNothing);
      await tester.scrollUntilVisible(
        find.byKey(const Key('chat-load-older')),
        500,
        scrollable: find
            .descendant(
              of: find.byKey(const Key('chat-list')),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      await tapKey(tester, 'chat-load-older');
      await tester.scrollUntilVisible(
        find.byKey(const Key('chat-message-M000')),
        500,
        scrollable: find
            .descendant(
              of: find.byKey(const Key('chat-list')),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      expect(find.byKey(const Key('chat-message-M000')), findsOneWidget);
      expect(find.byKey(const Key('chat-load-older')), findsNothing);
    });

    testWidgets('a group chat names the sender', (tester) async {
      server.chats[1].messages.add(
        messageJson(
          'M-ALAN',
          'Heading out',
          senderId: 'S-ALAN',
          senderName: 'Alan Turing',
          createdAt: '2026-10-14T02:00:00+00:00',
        ),
      );
      await pumpApp(tester);
      await openTab(tester, 'Messages');
      await tapKey(tester, 'messages-row-C-TEAM');

      expect(
        inKey('chat-message-M-ALAN', find.text('Alan Turing')),
        findsOneWidget,
      );
      expect(
        inKey('chat-message-M-G', find.text('Ada Lovelace')),
        findsNothing,
      );
    });

    testWidgets('an empty chat says so', (tester) async {
      await pumpApp(tester);
      await openTab(tester, 'Messages');
      await tapKey(tester, 'messages-row-C-EMPTY');

      expect(find.text('No messages yet. Say hello.'), findsOneWidget);
    });
  });

  group('New message (R-3)', () {
    testWidgets('pick a colleague (never myself) and open our chat', (
      tester,
    ) async {
      await pumpApp(tester);
      await openTab(tester, 'Messages');
      await tapKey(tester, 'messages-new');

      expect(find.byType(NewMessagePage), findsOneWidget);
      expect(find.byKey(const Key('new-message-person-$meId')), findsNothing);
      await tapKey(tester, 'new-message-person-S-LIN');

      expect(find.byType(ChatPage), findsOneWidget);
      final opened = requests.singleWhere(
        (r) => r.url.path.endsWith('/conversations/direct'),
      );
      expect(server.bodyOf(opened), {'staff_id': 'S-LIN'});
      expect(find.text('Person S-LIN'), findsOneWidget);
      // Back returns to the list, not to the picker.
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byType(ConversationsPage), findsOneWidget);
    });

    testWidgets('an existing chat is reused', (tester) async {
      await pumpApp(tester);
      await openTab(tester, 'Messages');
      await tapKey(tester, 'messages-new');
      await tapKey(tester, 'new-message-person-S-GRACE');

      expect(find.text('Are you in today?'), findsOneWidget);
      expect(server.chats, hasLength(3));
    });
  });

  group('Notifications (R-6)', () {
    testWidgets('newest first, unread marked; a message one opens the chat', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMoreRow(tester, 'more-notifications');

      expect(find.byType(NotificationsPage), findsOneWidget);
      expect(
        find.byKey(const Key('notifications-unread-N-MSG')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('notifications-unread-N-ANN')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('notifications-unread-N-OLD')), findsNothing);
      expect(
        inKey('notifications-row-N-MSG', find.text('09:00')),
        findsOneWidget,
      );

      await tapKey(tester, 'notifications-row-N-MSG');

      expect(
        requests.map(target),
        contains('POST /me/notifications/N-MSG/read'),
      );
      expect(find.byType(ChatPage), findsOneWidget);
      expect(
        tester.widget<NavigationBar>(find.byType(NavigationBar)).selectedIndex,
        3,
      );
    });

    testWidgets('an announcement one opens the announcement in the same tab', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMoreRow(tester, 'more-notifications');
      await tapKey(tester, 'notifications-row-N-ANN');

      expect(find.byType(AnnouncementPage), findsOneWidget);
      final router = GoRouter.of(tester.element(find.byType(AnnouncementPage)));
      expect(router.state.matchedLocation, '/more/announcements/A1');
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('notifications-unread-N-ANN')), findsNothing);
    });

    testWidgets('Mark all read', (tester) async {
      await pumpApp(tester);
      await openMoreRow(tester, 'more-notifications');

      await tapKey(tester, 'notifications-read-all');

      expect(find.text('All marked read.'), findsOneWidget);
      expect(find.byKey(const Key('notifications-unread-N-MSG')), findsNothing);
      expect(
        tester
            .widget<IconButton>(find.byKey(const Key('notifications-read-all')))
            .onPressed,
        isNull,
      );
    });

    testWidgets('works without a staff profile', (tester) async {
      server.myStaffId = null;
      await pumpApp(tester);
      await openMoreRow(tester, 'more-notifications');

      expect(find.byKey(const Key('notifications-row-N-MSG')), findsOneWidget);
    });
  });

  group('Announcements (R-7)', () {
    testWidgets('the feed marks new ones; acknowledge in the detail', (
      tester,
    ) async {
      await pumpApp(tester);
      await openMoreRow(tester, 'more-announcements');

      expect(find.byType(AnnouncementsPage), findsOneWidget);
      expect(inKey('announcements-row-A1', find.text('New')), findsOneWidget);
      expect(inKey('announcements-row-A2', find.text('New')), findsNothing);
      expect(
        inKey('announcements-row-A1', find.text('13 Oct 2026')),
        findsOneWidget,
      );

      await tapKey(tester, 'announcements-row-A1');
      expect(
        find.text('The office is closed on Friday.\nEnjoy the long weekend.'),
        findsOneWidget,
      );
      // 02:00 UTC is 10:00 in Manila.
      expect(find.text('Ada Admin · 13 Oct 2026 10:00'), findsOneWidget);

      await tapKey(tester, 'announcement-acknowledge');
      expect(
        requests.map(target),
        contains('POST /me/announcements/A1/acknowledge'),
      );
      expect(
        inKey(
          'announcement-acknowledged',
          find.text('Acknowledged on 14 Oct 2026 14:00'),
        ),
        findsOneWidget,
      );

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(inKey('announcements-row-A1', find.text('New')), findsNothing);
    });

    testWidgets('no profile', (tester) async {
      server.myStaffId = null;
      await pumpApp(tester);
      await openMoreRow(tester, 'more-announcements');

      expect(find.byKey(const Key('announcements-no-profile')), findsOneWidget);
    });
  });

  group('appearance', () {
    for (final (label, brightness) in [
      ('light', Brightness.light),
      ('dark', Brightness.dark),
    ]) {
      testWidgets(
        'Messages, a chat, Notifications and an announcement fit a small phone in $label mode at 200% text',
        (tester) async {
          tester.platformDispatcher.platformBrightnessTestValue = brightness;
          tester.platformDispatcher.textScaleFactorTestValue = 2.0;
          addTearDown(tester.platformDispatcher.clearAllTestValues);
          tester.view.physicalSize =
              const Size(320, 568) * tester.view.devicePixelRatio;
          addTearDown(tester.view.resetPhysicalSize);
          server.chats.first.messages.add(
            messageJson(
              'M-LONG',
              'A long message ' * 20,
              createdAt: '2026-10-14T02:00:00+00:00',
            ),
          );

          await pumpApp(tester, tall: false);
          await openTab(tester, 'Messages');
          expect(tester.takeException(), isNull);
          expect(
            Theme.of(tester.element(find.byType(ConversationsPage))).brightness,
            brightness,
          );
          await tapKey(tester, 'messages-row-C-GRACE');
          expect(tester.takeException(), isNull);
          expect(
            find.byKey(const Key('chat-send')).hitTestable(),
            findsOneWidget,
          );
          await tester.pageBack();
          await tester.pumpAndSettle();

          // More keeps its scroll position: open the upper row first.
          await openMoreRow(tester, 'more-announcements');
          await tapKey(tester, 'announcements-row-A1');
          expect(tester.takeException(), isNull);
          await tester.pageBack();
          await tester.pumpAndSettle();
          await tester.pageBack();
          await tester.pumpAndSettle();

          await openMoreRow(tester, 'more-notifications');
          expect(tester.takeException(), isNull);
        },
      );
    }

    testWidgets('controls meet tap-target and label guidelines', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      await pumpApp(tester);
      await openTab(tester, 'Messages');
      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));

      await tapKey(tester, 'messages-row-C-GRACE');
      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      semantics.dispose();
    });
  });
}

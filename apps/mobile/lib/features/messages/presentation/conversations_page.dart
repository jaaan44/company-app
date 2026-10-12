import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/core/presentation/paged_search_list.dart';
import 'package:mobile/features/messages/data/messages_api_client.dart';
import 'package:mobile/features/messages/domain/conversation.dart';
import 'package:mobile/features/messages/presentation/messages_widgets.dart';
import 'package:mobile/features/messages/state/conversations_controller.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// The Messages tab (Phase 32, R-3): my conversations, newest activity
/// first, with the last message, its time and an unread badge; **New
/// message** starts a direct chat. Reloads after any confirmed change
/// (R-11) — e.g. on returning from a chat.
class ConversationsPage extends StatefulWidget {
  const ConversationsPage({
    super.key,
    required this.messagesApiClient,
    required this.inboxChanges,
  });

  final MessagesApiClient messagesApiClient;
  final InboxChanges inboxChanges;

  @override
  State<ConversationsPage> createState() => _ConversationsPageState();
}

class _ConversationsPageState extends State<ConversationsPage> {
  late final ConversationsController _controller = ConversationsController(
    widget.messagesApiClient,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
    widget.inboxChanges.addListener(_controller.refresh);
  }

  @override
  void dispose() {
    widget.inboxChanges.removeListener(_controller.refresh);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Messages')),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('messages-new'),
        onPressed: () => context.push(
          newMessagePath,
          extra: ChatArgs(myStaffId: _controller.myStaffId),
        ),
        icon: const Icon(Icons.edit_outlined),
        label: const Text('New message'),
      ),
      body: ListenableBuilder(
        listenable: _controller,
        builder: (context, _) => PagedSearchList<Conversation>(
          controller: _controller,
          keyPrefix: 'messages',
          emptyMessage: 'No conversations yet.',
          errorFallback: 'Something went wrong loading your conversations.',
          rowBuilder: (context, c) => _ConversationRow(
            key: Key('messages-row-${c.publicId}'),
            conversation: c,
            myStaffId: _controller.myStaffId,
            companyDay: _controller.companyDay,
            onTap: () => context.push(
              chatPath(c.publicId),
              extra: ChatArgs(initial: c, myStaffId: _controller.myStaffId),
            ),
          ),
        ),
      ),
    );
  }
}

class _ConversationRow extends StatelessWidget {
  const _ConversationRow({
    super.key,
    required this.conversation,
    required this.myStaffId,
    required this.companyDay,
    required this.onTap,
  });

  final Conversation conversation;
  final String? myStaffId;
  final ScheduleCompanyDay? companyDay;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = conversation;
    final unread = c.unreadCount > 0;
    final day = companyDay;
    final last = c.lastMessage;

    return ListTile(
      onTap: onTap,
      leading: CircleAvatar(
        child: Icon(switch (c.kind) {
          ConversationKind.group => Icons.groups_outlined,
          ConversationKind.project => Icons.folder_outlined,
          _ => Icons.person_outline,
        }),
      ),
      title: Text(
        c.titleFor(myStaffId),
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: unread ? const TextStyle(fontWeight: FontWeight.bold) : null,
      ),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            lastMessagePreview(c, myStaffId),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: unread
                ? theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  )
                : null,
          ),
          if (last != null && day != null)
            Text(
              inboxTimeLabel(last.createdAt, day),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
        ],
      ),
      trailing: unread ? UnreadBadge(c.unreadCount) : null,
    );
  }
}

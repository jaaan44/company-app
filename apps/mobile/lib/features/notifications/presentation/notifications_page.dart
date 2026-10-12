import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/core/presentation/paged_search_list.dart';
import 'package:mobile/features/announcements/presentation/announcements_pages.dart'
    show AnnouncementArgs;
import 'package:mobile/features/messages/presentation/messages_widgets.dart';
import 'package:mobile/features/notifications/data/notifications_api_client.dart';
import 'package:mobile/features/notifications/domain/app_notification.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/notifications/state/notifications_controller.dart';

/// The Notifications inbox (Phase 32, R-6), opened from Home or More
/// ([base] is `/home` or `/more`, so an announcement opens in the same
/// tab). Tapping one marks it read and opens its source; **Mark all
/// read** is in the app bar.
class NotificationsPage extends StatefulWidget {
  const NotificationsPage({
    super.key,
    required this.notificationsApiClient,
    required this.inboxChanges,
    required this.base,
  });

  final NotificationsApiClient notificationsApiClient;
  final InboxChanges inboxChanges;
  final String base;

  @override
  State<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends State<NotificationsPage> {
  late final NotificationsController _controller = NotificationsController(
    widget.notificationsApiClient,
    changes: widget.inboxChanges,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
    // A chat read elsewhere also reads its notifications (R-2d).
    widget.inboxChanges.addListener(_controller.refresh);
  }

  @override
  void dispose() {
    widget.inboxChanges.removeListener(_controller.refresh);
    _controller.dispose();
    super.dispose();
  }

  Future<void> _open(AppNotification n) async {
    await _controller.markRead(n);
    if (!mounted) {
      return;
    }
    final source = n.source;
    switch (source?.kind) {
      case NotificationSourceKind.announcement:
        context.push(
          '${widget.base}/announcements/${Uri.encodeComponent(source!.publicId)}',
          extra: AnnouncementArgs(companyDay: _controller.companyDay),
        );
      case NotificationSourceKind.conversation:
        context.go(chatPath(source!.publicId));
      case NotificationSourceKind.unknown:
      case null:
        break;
    }
  }

  Future<void> _markAll() async {
    final ok = await _controller.markAllRead();
    if (!mounted) {
      return;
    }
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(
            ok ? 'All marked read.' : _controller.actionMessage ?? '',
          ),
        ),
      );
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _controller,
      builder: (context, _) => Scaffold(
        appBar: AppBar(
          title: const Text('Notifications'),
          actions: [
            // An icon with a tooltip: a text button doesn't fit beside the
            // title at large text sizes.
            IconButton(
              key: const Key('notifications-read-all'),
              tooltip: 'Mark all read',
              onPressed: _controller.hasUnread && !_controller.isMarkingAll
                  ? _markAll
                  : null,
              icon: const Icon(Icons.done_all),
            ),
          ],
        ),
        body: PagedSearchList<AppNotification>(
          controller: _controller,
          keyPrefix: 'notifications',
          emptyMessage: 'No notifications yet.',
          errorFallback: 'Something went wrong loading notifications.',
          rowBuilder: (context, n) => _NotificationRow(
            key: Key('notifications-row-${n.publicId}'),
            notification: n,
            time: _controller.companyDay == null
                ? null
                : inboxTimeLabel(n.createdAt, _controller.companyDay!),
            onTap: () => _open(n),
          ),
        ),
      ),
    );
  }
}

class _NotificationRow extends StatelessWidget {
  const _NotificationRow({
    super.key,
    required this.notification,
    required this.time,
    required this.onTap,
  });

  final AppNotification notification;
  final String? time;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final n = notification;
    final unread = n.isUnread;

    return Semantics(
      label: unread ? 'Unread' : null,
      child: ListTile(
        onTap: onTap,
        leading: Icon(
          n.source?.kind == NotificationSourceKind.conversation
              ? Icons.chat_bubble_outline
              : Icons.campaign_outlined,
        ),
        title: Text(
          n.title,
          style: unread ? const TextStyle(fontWeight: FontWeight.bold) : null,
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(n.message),
            if (time != null)
              Text(
                time!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
          ],
        ),
        trailing: unread
            ? Container(
                key: Key('notifications-unread-${n.publicId}'),
                width: 10,
                height: 10,
                decoration: BoxDecoration(
                  color: theme.colorScheme.primary,
                  shape: BoxShape.circle,
                ),
              )
            : null,
      ),
    );
  }
}

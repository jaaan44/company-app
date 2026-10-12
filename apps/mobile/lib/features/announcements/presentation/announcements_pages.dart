import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/core/presentation/paged_search_list.dart';
import 'package:mobile/features/announcements/data/announcements_api_client.dart';
import 'package:mobile/features/announcements/domain/announcement.dart';
import 'package:mobile/features/announcements/state/announcements_controllers.dart';
import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/messages/presentation/messages_widgets.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// The Announcements feed (Phase 32, R-7), from Home or More ([base]).
class AnnouncementsPage extends StatefulWidget {
  const AnnouncementsPage({
    super.key,
    required this.announcementsApiClient,
    required this.inboxChanges,
    required this.base,
  });

  final AnnouncementsApiClient announcementsApiClient;
  final InboxChanges inboxChanges;
  final String base;

  @override
  State<AnnouncementsPage> createState() => _AnnouncementsPageState();
}

class _AnnouncementsPageState extends State<AnnouncementsPage> {
  late final AnnouncementsController _controller = AnnouncementsController(
    widget.announcementsApiClient,
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
      appBar: AppBar(title: const Text('Announcements')),
      body: PagedSearchList<Announcement>(
        controller: _controller,
        keyPrefix: 'announcements',
        emptyMessage: 'No announcements yet.',
        errorFallback: 'Something went wrong loading announcements.',
        rowBuilder: (context, a) => ListTile(
          key: Key('announcements-row-${a.publicId}'),
          title: Text(
            a.title,
            style: a.isAcknowledged
                ? null
                : const TextStyle(fontWeight: FontWeight.bold),
          ),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (a.publishedAt != null && _controller.companyDay != null)
                Text(
                  formatDate(
                    _controller.companyDay!.toCompanyTime(a.publishedAt!),
                  ),
                ),
              if (!a.isAcknowledged) ...[
                const SizedBox(height: 4),
                const _NewChip(),
              ],
            ],
          ),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => context.push(
            '${widget.base}/announcements/${Uri.encodeComponent(a.publicId)}',
            extra: AnnouncementArgs(
              initial: a,
              companyDay: _controller.companyDay,
            ),
          ),
        ),
      ),
    );
  }
}

/// What the feed passes to a detail.
class AnnouncementArgs {
  const AnnouncementArgs({this.initial, this.companyDay});

  final Announcement? initial;
  final ScheduleCompanyDay? companyDay;
}

class _NewChip extends StatelessWidget {
  const _NewChip();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Semantics(
      label: 'Not yet acknowledged',
      excludeSemantics: true,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: scheme.primaryContainer,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
          child: Text(
            'New',
            style: Theme.of(context).textTheme.labelMedium
                ?.copyWith(color: scheme.onPrimaryContainer),
          ),
        ),
      ),
    );
  }
}

/// One announcement (R-7): title, published by and when, the full body
/// as selectable plain text, and **Acknowledge** until acknowledged.
class AnnouncementPage extends StatefulWidget {
  const AnnouncementPage({
    super.key,
    required this.announcementsApiClient,
    required this.inboxChanges,
    required this.publicId,
    this.args,
  });

  final AnnouncementsApiClient announcementsApiClient;
  final InboxChanges inboxChanges;
  final String publicId;
  final AnnouncementArgs? args;

  @override
  State<AnnouncementPage> createState() => _AnnouncementPageState();
}

class _AnnouncementPageState extends State<AnnouncementPage> {
  late final AnnouncementController _controller = AnnouncementController(
    widget.announcementsApiClient,
    widget.publicId,
    initial: widget.args?.initial,
    changes: widget.inboxChanges,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  /// Company time when the feed gave the offset; otherwise device time,
  /// labelled as such.
  String _when(DateTime instant) {
    final day = widget.args?.companyDay;

    return day == null
        ? '${formatDate(instant.toLocal())} (device time)'
        : '${formatDate(day.toCompanyTime(instant))} '
              '${formatTime(day.toCompanyTime(instant))}';
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _controller,
      builder: (context, _) => Scaffold(
        appBar: AppBar(title: const Text('Announcement')),
        body: LayoutBuilder(
          builder: (context, constraints) =>
              _body(context, peopleListPadding(constraints)),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_controller.status) {
      case AnnouncementStatusView.loading:
        return const Center(child: CircularProgressIndicator());
      case AnnouncementStatusView.noProfile:
        return const InboxNoProfile(keyName: 'announcement-no-profile');
      case AnnouncementStatusView.error:
        return PeopleErrorView(
          message:
              _controller.errorMessage ??
              'Something went wrong loading this announcement.',
          onRetry: _controller.load,
        );
      case AnnouncementStatusView.loaded:
        break;
    }
    final a = _controller.data!;
    final theme = Theme.of(context);
    final message = _controller.actionMessage;
    final by = [
      if (a.publishedBy != null) a.publishedBy!,
      if (a.publishedAt != null) _when(a.publishedAt!),
    ].join(' · ');

    return ListView(
      key: const Key('announcement-detail'),
      padding: padding.add(const EdgeInsets.all(16)),
      children: [
        Semantics(
          header: true,
          child: Text(a.title, style: theme.textTheme.headlineSmall),
        ),
        if (by.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(
            by,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
        const SizedBox(height: 16),
        SelectableText(a.body, style: theme.textTheme.bodyLarge),
        const SizedBox(height: 24),
        if (message != null) ...[
          Semantics(
            liveRegion: true,
            child: Text(
              message,
              key: const Key('announcement-error'),
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
          const SizedBox(height: 8),
        ],
        if (a.isAcknowledged)
          Row(
            key: const Key('announcement-acknowledged'),
            children: [
              Icon(
                Icons.check_circle_outline,
                color: theme.colorScheme.primary,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text('Acknowledged on ${_when(a.acknowledgedAt!)}'),
              ),
            ],
          )
        else
          FilledButton(
            key: const Key('announcement-acknowledge'),
            onPressed: _controller.isAcknowledging
                ? null
                : _controller.acknowledge,
            child: _controller.isAcknowledging
                ? const SizedBox.square(
                    dimension: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Acknowledge'),
          ),
      ],
    );
  }
}

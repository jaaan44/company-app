import 'package:flutter/material.dart';

import 'package:mobile/app/auth_scope.dart';
import 'package:mobile/features/home/data/home_api_client.dart';
import 'package:mobile/features/home/domain/home_summary.dart';
import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/home/state/home_controller.dart';

/// The employee Home (Phase 27, docs/phases/V1_PHASE_27_DEFINITION.md §8):
/// greeting, Today, Needs attention and Latest announcements — the
/// authenticated person's own data from `GET /me/home`, read-only.
///
/// **Nothing in the content is tappable (R-1).** Rows and tiles are plain
/// layout — no `InkWell`/`GestureDetector`/`ListTile`/chevron — because the
/// Tasks/Schedule/Messages destinations are still placeholders; later
/// phases add navigation when their screens exist. The only controls are
/// logout, "Try again", and pull-to-refresh.
///
/// Loads once, in [State.initState] (the shell keeps this branch mounted
/// across tab switches, so returning to Home doesn't re-fetch); refreshes
/// only on pull. Session expiry is handled entirely by `ApiClient` and the
/// router.
class HomePage extends StatefulWidget {
  const HomePage({super.key, required this.homeApiClient});

  final HomeApiClient homeApiClient;

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  late final HomeController _controller = HomeController(widget.homeApiClient);

  static const _maxContentWidth = 640.0;

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

  Future<void> _refresh() async {
    final ok = await _controller.refresh();
    if (!ok && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text("Couldn't refresh. Showing earlier information."),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final authController = AuthScope.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Home'),
        actions: [
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Log out',
            onPressed: authController.logout,
          ),
        ],
      ),
      body: ListenableBuilder(
        listenable: _controller,
        builder: (context, _) {
          final summary = _controller.summary;

          if (summary != null) {
            return RefreshIndicator(
              onRefresh: _refresh,
              child: _HomeContent(summary: summary, maxWidth: _maxContentWidth),
            );
          }

          if (_controller.status == HomeStatus.error) {
            return _HomeError(
              message:
                  _controller.errorMessage ??
                  'Something went wrong loading your Home.',
              onRetry: _controller.load,
            );
          }

          return const Center(child: CircularProgressIndicator());
        },
      ),
    );
  }
}

class _HomeError extends StatelessWidget {
  const _HomeError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Semantics(
              liveRegion: true,
              child: Text(
                message,
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: onRetry, child: const Text('Try again')),
          ],
        ),
      ),
    );
  }
}

class _HomeContent extends StatelessWidget {
  const _HomeContent({required this.summary, required this.maxWidth});

  final HomeSummary summary;
  final double maxWidth;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final gutter = constraints.maxWidth > maxWidth + 32
            ? (constraints.maxWidth - maxWidth) / 2
            : 16.0;
        final today = summary.today;
        final announcements = summary.announcements;

        return ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: EdgeInsets.symmetric(horizontal: gutter, vertical: 16),
          children: [
            _Greeting(summary: summary),
            if (today != null) ...[
              const SizedBox(height: 16),
              _TodayCard(today: today, day: summary.companyDay),
            ],
            const SizedBox(height: 16),
            _NeedsAttention(summary: summary),
            if (announcements != null) ...[
              const SizedBox(height: 16),
              _AnnouncementsCard(
                announcements: announcements,
                day: summary.companyDay,
              ),
            ],
          ],
        );
      },
    );
  }
}

class _Greeting extends StatelessWidget {
  const _Greeting({required this.summary});

  final HomeSummary summary;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final staff = summary.staff;
    final secondary = theme.textTheme.bodyMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    final roleLine = [
      staff?.positionTitle,
      staff?.departmentName,
    ].whereType<String>().where((part) => part.isNotEmpty).join(' · ');
    final team = staff?.teamName;

    return Column(
      key: const Key('home-greeting'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          header: true,
          child: Text(
            'Hello, ${staff?.greetingName ?? summary.user.name}',
            style: theme.textTheme.headlineSmall,
          ),
        ),
        if (staff != null && roleLine.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(roleLine, style: secondary),
        ],
        if (team != null && team.isNotEmpty) ...[
          const SizedBox(height: 2),
          Text(team, style: secondary),
        ],
        if (staff == null) ...[
          const SizedBox(height: 8),
          Text(
            "Your account isn't linked to an employee profile, so some sections aren't available.",
            style: secondary,
          ),
        ],
      ],
    );
  }
}

class _SectionCard extends StatelessWidget {
  const _SectionCard({super.key, required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Semantics(
              header: true,
              child: Text(
                title,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            const SizedBox(height: 8),
            ...children,
          ],
        ),
      ),
    );
  }
}

class _TodayCard extends StatelessWidget {
  const _TodayCard({required this.today, required this.day});

  final HomeToday today;
  final CompanyDay day;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final more = today.moreCount;

    return _SectionCard(
      key: const Key('home-today'),
      title: 'Today · ${formatDayMonth(day.date)}',
      children: [
        if (today.items.isEmpty)
          Text(
            'Nothing scheduled for you today.',
            style: theme.textTheme.bodyMedium,
          )
        else
          for (final item in today.items)
            _TodayRow(key: ValueKey(item.publicId), item: item, day: day),
        if (more > 0) ...[
          const SizedBox(height: 8),
          Text('+$more more today', style: theme.textTheme.bodyMedium),
        ],
      ],
    );
  }
}

class _TodayRow extends StatelessWidget {
  const _TodayRow({super.key, required this.item, required this.day});

  final TodayItem item;
  final CompanyDay day;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final label = todayItemLabel(item);
    final time = formatTodayTime(item, day);

    return MergeSemantics(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ExcludeSemantics(
              child: Icon(
                item.kind == TodayItemKind.task ? Icons.task_alt : Icons.event,
                color: theme.colorScheme.primary,
              ),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    item.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    '$label · $time',
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _NeedsAttention extends StatelessWidget {
  const _NeedsAttention({required this.summary});

  final HomeSummary summary;

  @override
  Widget build(BuildContext context) {
    final tasks = summary.tasks;
    final messages = summary.unreadMessages;
    final notifications = summary.unreadNotifications;

    final tiles = <Widget>[
      if (tasks != null)
        _CountTile(
          key: const Key('home-tile-tasks'),
          title: 'My tasks',
          value: tasks.open,
          caption: 'open',
          semanticsLabel:
              'My tasks: ${tasks.open} open, ${tasks.overdue} overdue, ${tasks.dueToday} due today',
          details: [
            _Detail('${tasks.overdue} overdue', isError: tasks.overdue > 0),
            _Detail('${tasks.dueToday} due today'),
          ],
        ),
      if (messages != null)
        _CountTile(
          key: const Key('home-tile-messages'),
          title: 'Messages',
          value: messages,
          caption: messages == 0 ? 'No unread messages' : 'unread',
          semanticsLabel: messages == 0
              ? 'No unread messages'
              : '$messages unread ${messages == 1 ? 'message' : 'messages'}',
        ),
      _CountTile(
        key: const Key('home-tile-notifications'),
        title: 'Notifications',
        value: notifications,
        caption: notifications == 0 ? 'No unread notifications' : 'unread',
        semanticsLabel: notifications == 0
            ? 'No unread notifications'
            : '$notifications unread ${notifications == 1 ? 'notification' : 'notifications'}',
      ),
    ];

    return Column(
      key: const Key('home-attention'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          header: true,
          child: Text(
            'Needs attention',
            style: Theme.of(context).textTheme.titleMedium,
          ),
        ),
        const SizedBox(height: 8),
        LayoutBuilder(
          builder: (context, constraints) {
            const spacing = 12.0;
            // Two columns normally; one when the text is scaled up or the
            // space is narrow, so nothing clips.
            final scaled = MediaQuery.textScalerOf(context).scale(10) > 13;
            final twoColumns = !scaled && constraints.maxWidth >= 320;
            final width = twoColumns
                ? (constraints.maxWidth - spacing) / 2
                : constraints.maxWidth;

            return Wrap(
              spacing: spacing,
              runSpacing: spacing,
              children: [
                for (final tile in tiles) SizedBox(width: width, child: tile),
              ],
            );
          },
        ),
      ],
    );
  }
}

class _Detail {
  const _Detail(this.text, {this.isError = false});

  final String text;
  final bool isError;
}

class _CountTile extends StatelessWidget {
  const _CountTile({
    super.key,
    required this.title,
    required this.value,
    required this.caption,
    required this.semanticsLabel,
    this.details = const [],
  });

  final String title;
  final int value;
  final String caption;
  final String semanticsLabel;
  final List<_Detail> details;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Semantics(
      container: true,
      label: semanticsLabel,
      excludeSemantics: true,
      child: Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: theme.textTheme.labelLarge),
              const SizedBox(height: 4),
              Text('$value', style: theme.textTheme.headlineSmall),
              Text(caption, style: theme.textTheme.bodyMedium),
              for (final detail in details)
                Text(
                  detail.text,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: detail.isError
                        ? theme.colorScheme.error
                        : theme.colorScheme.onSurfaceVariant,
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _AnnouncementsCard extends StatelessWidget {
  const _AnnouncementsCard({required this.announcements, required this.day});

  final List<AnnouncementPreview> announcements;
  final CompanyDay day;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return _SectionCard(
      key: const Key('home-announcements'),
      title: 'Latest announcements',
      children: [
        if (announcements.isEmpty)
          Text('No announcements yet.', style: theme.textTheme.bodyMedium)
        else
          for (final announcement in announcements)
            MergeSemantics(
              key: ValueKey(announcement.publicId),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      announcement.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall,
                    ),
                    if (announcement.publishedAt case final published?)
                      Text(
                        formatDate(day.toCompanyTime(published)),
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                  ],
                ),
              ),
            ),
      ],
    );
  }
}

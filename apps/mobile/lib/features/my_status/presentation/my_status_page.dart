import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';
import 'package:mobile/features/my_status/presentation/my_status_widgets.dart';
import 'package:mobile/features/my_status/state/my_status_controller.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

/// More → My status (Phase 30B, docs/phases/V1_PHASE_30_DEFINITION.md
/// §6.5, R-13…R-16): my current status and when I set it, **Change
/// status** (confirmed, never optimistic), **Check in**, and my 5 newest
/// check-ins and status changes with links to all of them.
class MyStatusPage extends StatefulWidget {
  const MyStatusPage({super.key, required this.myStatusApiClient});

  final MyStatusApiClient myStatusApiClient;

  @override
  State<MyStatusPage> createState() => _MyStatusPageState();
}

class _MyStatusPageState extends State<MyStatusPage> {
  late final MyStatusController _controller = MyStatusController(
    widget.myStatusApiClient,
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

  Future<void> _refresh() async {
    if (!await _controller.refresh() && mounted) {
      showRefreshFailed(context);
    }
  }

  Future<void> _changeStatus() async {
    final chosen = await showModalBottomSheet<OperationalStatus>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => _StatusSheet(current: _controller.current?.status),
    );
    if (chosen == null || !mounted) {
      return;
    }

    final ok = await _controller.setStatus(chosen);
    if (!mounted) {
      return;
    }
    final messenger = ScaffoldMessenger.of(context)..hideCurrentSnackBar();
    if (ok) {
      messenger.showSnackBar(
        SnackBar(content: Text('Status set to ${chosen.label}.')),
      );
    } else if (_controller.saveError case final error?) {
      messenger.showSnackBar(SnackBar(content: Text(error)));
    }
  }

  Future<void> _checkIn() async {
    final checkIn = await context.push<CheckIn>(checkInPath);
    if (checkIn == null || !mounted) {
      return;
    }
    _controller.addCheckIn(checkIn);
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(const SnackBar(content: Text('Checked in.')));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('My status')),
      body: ListenableBuilder(
        listenable: _controller,
        builder: (context, _) => LayoutBuilder(
          builder: (context, constraints) =>
              _body(context, peopleListPadding(constraints)),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_controller.state) {
      case MyStatusState.loading:
        return const Center(child: CircularProgressIndicator());
      case MyStatusState.error:
        return PeopleErrorView(
          message:
              _controller.errorMessage ??
              'Something went wrong loading your status.',
          onRetry: _controller.load,
        );
      case MyStatusState.noProfile:
        // R-15: no status, no Check in.
        return RefreshIndicator(
          onRefresh: _refresh,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(24),
            children: const [
              Text(
                key: Key('my-status-no-profile'),
                'No staff profile is linked to this account.',
                textAlign: TextAlign.center,
              ),
            ],
          ),
        );
      case MyStatusState.loaded:
        break;
    }

    final day = _controller.companyDay!;
    final saving = _controller.saving;
    final checkIns = _controller.recentCheckIns;
    final statuses = _controller.recentStatuses;

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        key: const Key('my-status-list'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: EdgeInsets.fromLTRB(padding.left, 0, padding.right, 16),
        children: [
          if (saving != null) const LinearProgressIndicator(),
          _CurrentStatus(entry: _controller.current, companyDay: day),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Wrap(
              spacing: 12,
              runSpacing: 8,
              children: [
                FilledButton.icon(
                  key: const Key('my-status-check-in'),
                  onPressed: saving == null ? _checkIn : null,
                  icon: const Icon(Icons.place_outlined),
                  label: const Text('Check in'),
                ),
                OutlinedButton.icon(
                  key: const Key('my-status-change'),
                  onPressed: saving == null ? _changeStatus : null,
                  icon: const Icon(Icons.edit_outlined),
                  label: const Text('Change status'),
                ),
              ],
            ),
          ),
          const PeopleSectionHeader('Recent check-ins'),
          if (checkIns.isEmpty)
            const _Empty(
              key: Key('my-status-no-check-ins'),
              text: "You haven't checked in yet.",
            ),
          for (final c in checkIns)
            CheckInRow(
              key: Key('my-status-check-in-${c.publicId}'),
              checkIn: c,
              companyDay: day,
            ),
          if (checkIns.isNotEmpty)
            _SeeAll(
              buttonKey: const Key('my-status-all-check-ins'),
              label: 'All check-ins',
              onTap: () => context.push(myCheckInsPath),
            ),
          const PeopleSectionHeader('Recent status changes'),
          if (statuses.isEmpty)
            const _Empty(
              key: Key('my-status-no-history'),
              text: "You haven't set a status yet.",
            ),
          for (final s in statuses)
            StatusRow(
              key: Key('my-status-history-${s.identity}'),
              entry: s,
              companyDay: day,
            ),
          if (statuses.isNotEmpty)
            _SeeAll(
              buttonKey: const Key('my-status-all-history'),
              label: 'All status changes',
              onTap: () => context.push(myStatusHistoryPath),
            ),
        ],
      ),
    );
  }
}

class _CurrentStatus extends StatelessWidget {
  const _CurrentStatus({required this.entry, required this.companyDay});

  final StatusEntry? entry;
  final ScheduleCompanyDay companyDay;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final entry = this.entry;
    final hint = entry?.status.hint;

    return Padding(
      key: const Key('my-status-current'),
      padding: const EdgeInsets.all(16),
      child: MergeSemantics(
        child: Row(
          children: [
            Icon(
              entry == null ? Icons.help_outline : statusIcon(entry.status),
              size: 40,
              color: theme.colorScheme.primary,
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    entry == null ? 'No status set yet' : entry.status.label,
                    style: theme.textTheme.headlineSmall,
                  ),
                  if (entry != null)
                    Text(
                      'Set ${companyTimeLabel(entry.recordedAt, companyDay)}',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  if (hint != null)
                    Text(
                      hint,
                      key: const Key('my-status-hint'),
                      style: theme.textTheme.bodyMedium,
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

/// The Change status sheet (R-16): the five statuses, each with its icon
/// and label, the current one marked. Choosing one closes the sheet with
/// it; the page then sends it and waits for the server.
class _StatusSheet extends StatelessWidget {
  const _StatusSheet({required this.current});

  final OperationalStatus? current;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
              child: Semantics(
                header: true,
                child: Text(
                  'Change status',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
            ),
            for (final status in OperationalStatus.choices)
              ListTile(
                key: Key('status-choice-${status.wireValue}'),
                leading: Icon(statusIcon(status)),
                title: Text(status.label),
                subtitle: status.hint == null ? null : Text(status.hint!),
                trailing: status == current
                    ? const Icon(Icons.check, semanticLabel: 'Current status')
                    : null,
                selected: status == current,
                onTap: () => Navigator.of(context).pop(status),
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
      child: Text(
        text,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }
}

class _SeeAll extends StatelessWidget {
  const _SeeAll({
    required this.buttonKey,
    required this.label,
    required this.onTap,
  });

  final Key buttonKey;

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8),
        child: TextButton(key: buttonKey, onPressed: onTap, child: Text(label)),
      ),
    );
  }
}

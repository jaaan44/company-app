import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/leave/presentation/leave_widgets.dart';
import 'package:mobile/features/leave/state/leave_changes.dart';
import 'package:mobile/features/leave/state/leave_overview_controller.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';

/// The Leave screen (Phase 31, R-1/R-9): this company year's balances, my
/// requests newest first with status chips, **Request leave**, and — for
/// reviewers who manage someone — **Team requests (N pending)**. Pull to
/// refresh; reloads quietly after any confirmed leave change (R-11).
class LeavePage extends StatefulWidget {
  const LeavePage({
    super.key,
    required this.leaveApiClient,
    required this.leaveChanges,
  });

  final LeaveApiClient leaveApiClient;
  final LeaveChanges leaveChanges;

  @override
  State<LeavePage> createState() => _LeavePageState();
}

class _LeavePageState extends State<LeavePage> {
  late final LeaveOverviewController _controller = LeaveOverviewController(
    widget.leaveApiClient,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
    widget.leaveChanges.addListener(_onLeaveChange);
  }

  @override
  void dispose() {
    widget.leaveChanges.removeListener(_onLeaveChange);
    _controller.dispose();
    super.dispose();
  }

  void _onLeaveChange() {
    if (_controller.status == LeaveOverviewStatus.loaded) {
      _controller.refresh();
    } else {
      _controller.load();
    }
  }

  Future<void> _refresh() async {
    if (!await _controller.refresh() && mounted) {
      showRefreshFailed(context);
    }
  }

  void _requestLeave() => context.push(
    leaveNewPath,
    extra: LeaveFormArgs(
      balances: _controller.balances,
      companyDate: _controller.companyDay?.date,
    ),
  );

  void _open(LeaveRequest request) => context.push(
    myLeaveRequestPath(request.publicId),
    extra: LeaveRequestArgs(
      initial: request,
      companyDay: _controller.companyDay,
    ),
  );

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _controller,
      builder: (context, _) => Scaffold(
        appBar: AppBar(title: const Text('Leave')),
        body: LayoutBuilder(
          builder: (context, constraints) =>
              _body(context, peopleListPadding(constraints)),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_controller.status) {
      case LeaveOverviewStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case LeaveOverviewStatus.noProfile:
        return const LeaveNoProfile();
      case LeaveOverviewStatus.error:
        return PeopleErrorView(
          message:
              _controller.errorMessage ??
              'Something went wrong loading your leave.',
          onRetry: _controller.load,
        );
      case LeaveOverviewStatus.loaded:
        break;
    }

    final requests = _controller.requests;
    final year = _controller.balanceYear;

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        key: const Key('leave-list'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: padding,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: FilledButton.icon(
              key: const Key('leave-request'),
              onPressed: _requestLeave,
              icon: const Icon(Icons.add),
              label: const Text('Request leave'),
            ),
          ),
          if (_controller.hasTeam)
            ListTile(
              key: const Key('leave-team'),
              leading: const Icon(Icons.groups_outlined),
              title: const Text('Team requests'),
              subtitle: Text(
                _controller.teamPendingCount == 0
                    ? 'Nothing waiting for you'
                    : '${_controller.teamPendingCount} pending',
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push(teamLeavePath),
            ),
          PeopleSectionHeader(year == null ? 'Balances' : 'Balances $year'),
          if (_controller.balances.isEmpty)
            const ListTile(title: Text('No leave types are available.')),
          for (final b in _controller.balances)
            ListTile(
              key: Key('leave-balance-${b.leaveType.publicId}'),
              leading: Icon(
                b.isPaid ? Icons.beach_access_outlined : Icons.money_off,
              ),
              title: Text(b.leaveType.name),
              subtitle: Text('${balanceHeadline(b)}\n${balanceDetail(b)}'),
              isThreeLine: true,
            ),
          const PeopleSectionHeader('My requests'),
          if (requests.isEmpty)
            const ListTile(
              key: Key('leave-empty'),
              title: Text("You haven't requested leave yet."),
            ),
          for (final r in requests)
            LeaveRequestTile(
              key: Key('leave-row-${r.publicId}'),
              request: r,
              onTap: () => _open(r),
            ),
          if (_controller.hasMore) _loadMoreRow(),
          const SizedBox(height: 24),
        ],
      ),
    );
  }

  Widget _loadMoreRow() {
    if (_controller.isLoadingMore) {
      return const Padding(
        key: Key('leave-loading-more'),
        padding: EdgeInsets.all(16),
        child: Center(child: CircularProgressIndicator()),
      );
    }

    return Padding(
      padding: const EdgeInsets.all(8),
      child: TextButton(
        key: const Key('leave-load-more'),
        onPressed: _controller.loadMore,
        child: Text(
          _controller.loadMoreFailed
              ? "Couldn't load more. Tap to try again."
              : 'Show more',
        ),
      ),
    );
  }
}

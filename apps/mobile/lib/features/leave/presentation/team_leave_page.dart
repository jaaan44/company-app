import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/core/presentation/paged_search_list.dart';
import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/leave/presentation/leave_widgets.dart';
import 'package:mobile/features/leave/state/leave_changes.dart';
import 'package:mobile/features/leave/state/team_leave_controller.dart';

/// Team requests (Phase 31, R-3/R-7): my direct reports' leave, pending
/// soonest first by default, with Approved, Rejected and All. A request
/// opens its detail, where it is approved or rejected; the list reloads
/// after any confirmed leave change (R-11).
class TeamLeavePage extends StatefulWidget {
  const TeamLeavePage({
    super.key,
    required this.leaveApiClient,
    required this.leaveChanges,
  });

  final LeaveApiClient leaveApiClient;
  final LeaveChanges leaveChanges;

  @override
  State<TeamLeavePage> createState() => _TeamLeavePageState();
}

class _TeamLeavePageState extends State<TeamLeavePage> {
  late final TeamLeaveController _controller = TeamLeaveController(
    widget.leaveApiClient,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
    widget.leaveChanges.addListener(_controller.refresh);
  }

  @override
  void dispose() {
    widget.leaveChanges.removeListener(_controller.refresh);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Team requests')),
      body: Column(
        children: [
          ListenableBuilder(
            listenable: _controller,
            builder: (context, _) => SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
              child: Row(
                children: [
                  for (final f in TeamLeaveFilter.values)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(
                        key: Key('team-leave-filter-${f.wireValue}'),
                        label: Text(
                          f == TeamLeaveFilter.pending &&
                                  _controller.pendingCount != null
                              ? 'Pending (${_controller.pendingCount})'
                              : f.label,
                        ),
                        selected: _controller.filter == f,
                        onSelected: (_) => _controller.setFilter(f),
                      ),
                    ),
                ],
              ),
            ),
          ),
          Expanded(
            child: ListenableBuilder(
              listenable: _controller,
              builder: (context, _) => PagedSearchList<LeaveRequest>(
                controller: _controller,
                keyPrefix: 'team-leave',
                emptyMessage: _controller.filter == TeamLeaveFilter.pending
                    ? 'No requests are waiting for you.'
                    : 'No requests here.',
                errorFallback: 'Something went wrong loading team requests.',
                rowBuilder: (context, request) => LeaveRequestTile(
                  key: Key('team-leave-row-${request.publicId}'),
                  request: request,
                  showRequester: true,
                  onTap: () => context.push(
                    teamLeaveRequestPath(request.publicId),
                    extra: LeaveRequestArgs(
                      initial: request,
                      companyDay: _controller.companyDay,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

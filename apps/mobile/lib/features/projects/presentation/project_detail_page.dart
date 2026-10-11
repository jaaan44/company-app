import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/people/state/resource_controller.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/projects/presentation/project_widgets.dart';
import 'package:mobile/features/projects/state/project_detail_controller.dart';

/// One project (Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md §7.5,
/// R-22): name, code, status, client (opens the client — R-25), dates, my
/// role and description, then members (leads first; each opens their Staff
/// directory entry) and milestones (date and status, no "overdue" — R-23).
/// Notes are not shown (R-24). A project I can't see (opened from a task)
/// shows "You don't have access to this project." with "Try again".
class ProjectDetailPage extends StatefulWidget {
  const ProjectDetailPage({
    super.key,
    required this.projectsApiClient,
    required this.publicId,
  });

  final ProjectsApiClient projectsApiClient;
  final String publicId;

  @override
  State<ProjectDetailPage> createState() => _ProjectDetailPageState();
}

class _ProjectDetailPageState extends State<ProjectDetailPage> {
  late final ProjectDetailController _controller = ProjectDetailController(
    widget.projectsApiClient,
    widget.publicId,
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Project')),
      body: ListenableBuilder(
        listenable: _controller,
        builder: (context, _) {
          final detail = _controller.data;

          if (detail != null) {
            return RefreshIndicator(
              onRefresh: _refresh,
              child: _DetailContent(detail: detail),
            );
          }

          if (_controller.status == ResourceStatus.error) {
            return PeopleErrorView(
              message:
                  _controller.errorMessage ??
                  'Something went wrong loading this project.',
              onRetry: _controller.load,
            );
          }

          return const Center(child: CircularProgressIndicator());
        },
      ),
    );
  }
}

class _DetailContent extends StatelessWidget {
  const _DetailContent({required this.detail});

  final ProjectDetail detail;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final project = detail.project;
    final client = project.client;
    final description = project.description?.trim() ?? '';

    return LayoutBuilder(
      builder: (context, constraints) => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: peopleListPadding(constraints),
        children: [
          Padding(
            key: const Key('project-header'),
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(
                  header: true,
                  child: Text(
                    project.name,
                    style: theme.textTheme.headlineSmall,
                  ),
                ),
                if (project.projectCode case final code?) ...[
                  const SizedBox(height: 2),
                  Text(
                    code,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
                const SizedBox(height: 8),
                ProjectStatusChip(project.status),
              ],
            ),
          ),
          PeopleInfoRow(
            key: const Key('project-client'),
            icon: Icons.business_outlined,
            label: 'Client',
            value: client?.name,
            onTap: client == null
                ? null
                : () => context.push(clientPath(client.publicId)),
          ),
          PeopleInfoRow(
            key: const Key('project-my-role'),
            icon: Icons.badge_outlined,
            label: 'My role',
            value: project.myRole?.label ?? 'Not a member',
          ),
          PeopleInfoRow(
            icon: Icons.play_circle_outline,
            label: 'Start date',
            value: formatApiDate(project.startDate),
          ),
          PeopleInfoRow(
            icon: Icons.flag_outlined,
            label: 'Target end date',
            value: formatApiDate(project.targetEndDate),
          ),
          if (project.completedDate != null)
            PeopleInfoRow(
              key: const Key('project-completed-date'),
              icon: Icons.check_circle_outline,
              label: 'Completed',
              value: formatApiDate(project.completedDate),
            ),
          if (description.isNotEmpty) ...[
            const PeopleSectionHeader('Description'),
            Padding(
              key: const Key('project-description'),
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
              child: Text(description),
            ),
          ],
          PeopleSectionHeader('Members (${detail.membersTotal})'),
          if (detail.members.isEmpty)
            const SectionNote(
              'No members yet.',
              key: Key('project-no-members'),
            ),
          for (final member in detail.members)
            ListTile(
              key: Key('project-member-${member.staffPublicId}'),
              leading: const Icon(Icons.person_outline),
              title: Text(member.displayName),
              subtitle: Text(member.role.label),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push(staffPath(member.staffPublicId)),
            ),
          ShowingCountNote(
            key: const Key('project-members-more'),
            shown: detail.members.length,
            total: detail.membersTotal,
          ),
          const PeopleSectionHeader('Milestones'),
          if (detail.milestones.isEmpty)
            const SectionNote(
              'No milestones yet.',
              key: Key('project-no-milestones'),
            ),
          for (final milestone in detail.milestones)
            ListTile(
              key: Key('project-milestone-${milestone.publicId}'),
              leading: const Icon(Icons.outlined_flag),
              title: Text(milestone.title),
              subtitle: Text(formatApiDate(milestone.dueDate)),
              trailing: MilestoneStatusChip(milestone.status),
            ),
          ShowingCountNote(
            key: const Key('project-milestones-more'),
            shown: detail.milestones.length,
            total: detail.milestonesTotal,
          ),
        ],
      ),
    );
  }
}

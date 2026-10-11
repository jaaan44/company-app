import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/core/presentation/paged_search_list.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/projects/domain/project.dart';
import 'package:mobile/features/projects/presentation/project_widgets.dart';
import 'package:mobile/features/projects/state/my_projects_controller.dart';

/// More → Projects (Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md §7.5):
/// the projects I'm a member of, for every role (R-20), in name order with
/// a search by name or code, paging and pull-to-refresh (R-21). Each row
/// shows the name, code and client, a status chip and "Project lead" where
/// it applies, and opens the project.
class ProjectsPage extends StatefulWidget {
  const ProjectsPage({super.key, required this.projectsApiClient});

  final ProjectsApiClient projectsApiClient;

  @override
  State<ProjectsPage> createState() => _ProjectsPageState();
}

class _ProjectsPageState extends State<ProjectsPage> {
  late final MyProjectsController _controller = MyProjectsController(
    widget.projectsApiClient,
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Projects')),
      body: PagedSearchList<Project>(
        controller: _controller,
        keyPrefix: 'projects',
        searchHint: 'Search by name or code',
        emptyMessage: "You aren't a member of any projects yet.",
        searchEmptyMessage: (q) => 'No project matches "$q".',
        errorFallback: 'Something went wrong loading your projects.',
        rowBuilder: (context, project) => _ProjectRow(project: project),
      ),
    );
  }
}

class _ProjectRow extends StatelessWidget {
  const _ProjectRow({required this.project});

  final Project project;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final details = [
      project.projectCode,
      project.client?.name,
    ].whereType<String>().join(' · ');

    return ListTile(
      key: Key('projects-row-${project.publicId}'),
      title: Text(project.name),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (details.isNotEmpty) Text(details),
          const SizedBox(height: 4),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              ProjectStatusChip(project.status),
              if (project.iAmLead)
                Text(
                  ProjectRole.projectLead.label,
                  key: Key('projects-lead-${project.publicId}'),
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: theme.colorScheme.primary,
                  ),
                ),
            ],
          ),
        ],
      ),
      trailing: const Icon(Icons.chevron_right),
      onTap: () => context.push(projectPath(project.publicId)),
    );
  }
}

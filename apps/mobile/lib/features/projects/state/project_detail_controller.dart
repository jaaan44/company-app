import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/people/state/resource_controller.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/projects/domain/project.dart';

/// Everything a project's detail shows (R-22): the project, its members
/// (leads first) and its milestones — each list one page of 50, with the
/// total so the screen can say "Showing 50 of N".
class ProjectDetail {
  const ProjectDetail({
    required this.project,
    required this.members,
    required this.membersTotal,
    required this.milestones,
    required this.milestonesTotal,
  });

  final Project project;
  final List<ProjectMember> members;
  final int membersTotal;
  final List<ProjectMilestone> milestones;
  final int milestonesTotal;
}

/// State for one project's detail (Phase 29C, spec §7.2/§7.4). The project,
/// members and milestones load together; any failure is the screen's
/// failure. A `403` — a project I'm not a member of, opened from a task
/// (R-25) — is "You don't have access to this project." and never ends the
/// session (the Phase 27 rule is unchanged).
///
/// Owned by its page for the page's lifetime (DEC-025).
class ProjectDetailController extends ResourceController<ProjectDetail> {
  ProjectDetailController(this._client, this.publicId);

  final ProjectsApiClient _client;
  final String publicId;

  @override
  Future<ProjectDetail> fetch() async {
    // Together, and every request awaited: one failing never leaves the
    // others' errors unhandled.
    final results = await Future.wait<Object>([
      _client.fetchProject(publicId),
      _client.fetchMembers(publicId),
      _client.fetchMilestones(publicId),
    ]);
    final project = results[0] as Project;
    final members = results[1] as PagedResult<ProjectMember>;
    final milestones = results[2] as PagedResult<ProjectMilestone>;

    return ProjectDetail(
      project: project,
      members: leadsFirst(members.items),
      membersTotal: members.total,
      milestones: milestones.items,
      milestonesTotal: milestones.total,
    );
  }

  @override
  String messageFor(ApiException e) => switch (e) {
    ApiNetworkException() =>
      "Couldn't load this project. Check your connection.",
    ApiForbiddenException() => "You don't have access to this project.",
    ApiRequestException(statusCode: 404) => 'This project no longer exists.',
    _ => 'Something went wrong loading this project.',
  };
}

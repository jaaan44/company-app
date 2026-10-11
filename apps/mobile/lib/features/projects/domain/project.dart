/// Typed views of the Phase 10/17 project resources the app reads (Phase
/// 29C, docs/phases/V1_PHASE_29_DEFINITION.md §7): `ProjectResource`
/// (`GET /projects`, `/projects/{id}`), `ProjectMembershipResource`
/// (`/projects/{id}/members`) and `ProjectMilestoneResource`
/// (`/projects/{id}/milestones`).
///
/// Parsing is strict: a required field that is missing or of the wrong type,
/// or an unknown status or role, throws a [FormatException]; a nullable
/// field stays null. `notes` is deliberately not read (R-24).
library;

/// A project's status (Phase 10 `ProjectStatus`).
enum ProjectStatus {
  planned('planned', 'Planned'),
  active('active', 'Active'),
  onHold('on_hold', 'On hold'),
  completed('completed', 'Completed'),
  cancelled('cancelled', 'Cancelled');

  const ProjectStatus(this.wireValue, this.label);

  final String wireValue;
  final String label;

  bool get isClosed =>
      this == ProjectStatus.completed || this == ProjectStatus.cancelled;

  static ProjectStatus fromWire(Object? value) =>
      ProjectStatus.values.firstWhere(
        (s) => s.wireValue == value,
        orElse: () => throw FormatException('Unknown project status: $value'),
      );
}

/// A member's role on a project (Phase 10 `ProjectMembershipRole`).
enum ProjectRole {
  projectLead('project_lead', 'Project lead'),
  member('member', 'Member');

  const ProjectRole(this.wireValue, this.label);

  final String wireValue;
  final String label;

  static ProjectRole fromWire(Object? value) => ProjectRole.values.firstWhere(
    (r) => r.wireValue == value,
    orElse: () => throw FormatException('Unknown project role: $value'),
  );
}

/// A milestone's status (Phase 17 `ProjectMilestoneStatus`).
enum MilestoneStatus {
  pending('pending', 'Pending'),
  completed('completed', 'Completed'),
  cancelled('cancelled', 'Cancelled');

  const MilestoneStatus(this.wireValue, this.label);

  final String wireValue;
  final String label;

  static MilestoneStatus fromWire(Object? value) =>
      MilestoneStatus.values.firstWhere(
        (s) => s.wireValue == value,
        orElse: () => throw FormatException('Unknown milestone status: $value'),
      );
}

/// A `{public_id, name}` reference (a project's client).
class NamedRef {
  const NamedRef({required this.publicId, required this.name});

  factory NamedRef.fromJson(Map<String, dynamic> json) => NamedRef(
    publicId: json['public_id'] as String,
    name: json['name'] as String,
  );

  final String publicId;
  final String name;
}

/// One project, as both the list and the detail show it (the API returns
/// the same shape for both).
class Project {
  const Project({
    required this.publicId,
    required this.name,
    required this.status,
    this.projectCode,
    this.description,
    this.startDate,
    this.targetEndDate,
    this.completedDate,
    this.client,
    this.myRole,
    this.membersCount,
  });

  factory Project.fromJson(Map<String, dynamic> json) {
    try {
      final client = json['client'];
      final myRole = json['my_role'];

      return Project(
        publicId: json['public_id'] as String,
        name: json['name'] as String,
        status: ProjectStatus.fromWire(json['status']),
        projectCode: json['project_code'] as String?,
        description: json['description'] as String?,
        startDate: _date(json['start_date']),
        targetEndDate: _date(json['target_end_date']),
        completedDate: _date(json['completed_date']),
        client: client == null
            ? null
            : NamedRef.fromJson(client as Map<String, dynamic>),
        myRole: myRole == null ? null : ProjectRole.fromWire(myRole),
        membersCount: json['members_count'] as int?,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected project shape: $e');
    }
  }

  final String publicId;
  final String name;
  final ProjectStatus status;
  final String? projectCode;
  final String? description;

  /// `YYYY-MM-DD` calendar dates.
  final String? startDate;
  final String? targetEndDate;
  final String? completedDate;
  final NamedRef? client;

  /// The signed-in person's role, or null when they aren't a member.
  final ProjectRole? myRole;
  final int? membersCount;

  bool get iAmLead => myRole == ProjectRole.projectLead;
}

/// One row of `GET /projects/{id}/members`.
class ProjectMember {
  const ProjectMember({
    required this.staffPublicId,
    required this.displayName,
    required this.role,
    this.employeeNumber,
  });

  factory ProjectMember.fromJson(Map<String, dynamic> json) {
    try {
      final staff = json['staff'] as Map<String, dynamic>;

      return ProjectMember(
        staffPublicId: staff['public_id'] as String,
        displayName: staff['display_name'] as String,
        employeeNumber: staff['employee_number'] as String?,
        role: ProjectRole.fromWire(json['role']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected project member shape: $e');
    }
  }

  /// The Staff directory entry this member opens (R-25).
  final String staffPublicId;
  final String displayName;
  final String? employeeNumber;
  final ProjectRole role;
}

/// One row of `GET /projects/{id}/milestones`.
class ProjectMilestone {
  const ProjectMilestone({
    required this.publicId,
    required this.title,
    required this.dueDate,
    required this.status,
  });

  factory ProjectMilestone.fromJson(Map<String, dynamic> json) {
    try {
      return ProjectMilestone(
        publicId: json['public_id'] as String,
        title: json['title'] as String,
        dueDate: _date(json['due_date'])!,
        status: MilestoneStatus.fromWire(json['status']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected milestone shape: $e');
    }
  }

  final String publicId;
  final String title;

  /// `YYYY-MM-DD`. No "overdue" label is derived from it (R-23).
  final String dueDate;
  final MilestoneStatus status;
}

/// Leads first, then everyone else, each group keeping the server's order
/// (R-22). The server orders by when each person joined, then id (R-19).
List<ProjectMember> leadsFirst(List<ProjectMember> members) => [
  ...members.where((m) => m.role == ProjectRole.projectLead),
  ...members.where((m) => m.role != ProjectRole.projectLead),
];

final _datePattern = RegExp(r'^\d{4}-\d{2}-\d{2}$');

String? _date(Object? value) {
  if (value == null) {
    return null;
  }
  if (value is! String || !_datePattern.hasMatch(value)) {
    throw FormatException('Not a YYYY-MM-DD date: $value');
  }

  return value;
}

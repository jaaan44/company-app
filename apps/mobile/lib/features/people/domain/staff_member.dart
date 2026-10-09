/// Typed view of the Phase 7 `StaffResource` (`GET /api/v1/staff`,
/// `GET /api/v1/staff/{public_id}`, and `staff` in `GET /api/v1/me/profile`)
/// — Phase 28, docs/phases/V1_PHASE_28_DEFINITION.md §6–§7.
///
/// Only the fields the People screens use are parsed. Deliberately not
/// parsed: `operational_status` (R-4 — Phase 30 owns status), the
/// Administrator-only `user` block, `has_user_account`, `separation_date`
/// and timestamps.
///
/// Parsing is strict: a required field that is missing or of the wrong type
/// throws a [FormatException]; a nullable field stays null — never replaced
/// with a placeholder.
class StaffMember {
  const StaffMember({
    required this.publicId,
    required this.firstName,
    required this.lastName,
    required this.displayName,
    required this.status,
    this.preferredName,
    this.employeeNumber,
    this.companyEmail,
    this.companyPhone,
    this.hireDate,
    this.position,
    this.department,
    this.team,
    this.manager,
  });

  factory StaffMember.fromJson(Map<String, dynamic> json) {
    try {
      return StaffMember(
        publicId: json['public_id'] as String,
        firstName: json['first_name'] as String,
        lastName: json['last_name'] as String,
        displayName: json['display_name'] as String,
        status: json['status'] as String,
        preferredName: json['preferred_name'] as String?,
        employeeNumber: json['employee_number'] as String?,
        companyEmail: json['company_email'] as String?,
        companyPhone: json['company_phone'] as String?,
        hireDate: json['hire_date'] as String?,
        position: OrgRef.fromNullable(json['position'], nameKey: 'title'),
        department: OrgRef.fromNullable(json['department']),
        team: OrgRef.fromNullable(json['team']),
        manager: OrgRef.fromNullable(json['manager'], nameKey: 'display_name'),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected staff record shape: $e');
    }
  }

  final String publicId;
  final String firstName;
  final String lastName;

  /// The server's `preferred_name ?: "first last"` (Phase 7).
  final String displayName;

  /// Employment status (`active` / `inactive` / `separated`).
  final String status;
  final String? preferredName;
  final String? employeeNumber;
  final String? companyEmail;
  final String? companyPhone;

  /// `YYYY-MM-DD`, as the API returns it.
  final String? hireDate;
  final OrgRef? position;
  final OrgRef? department;
  final OrgRef? team;
  final OrgRef? manager;

  String get fullName => '$firstName $lastName';

  /// True when [displayName] is a preferred name that differs from the full
  /// name — the detail screen then shows both (spec §8).
  bool get hasDistinctFullName => displayName != fullName;

  /// "position · department", skipping whichever is missing; null when
  /// both are (spec §8 list subtitle).
  String? get roleLine {
    final parts = [
      position?.name,
      department?.name,
    ].whereType<String>().where((p) => p.isNotEmpty);

    return parts.isEmpty ? null : parts.join(' · ');
  }
}

/// A `{public_id, <name>}` reference to a Department, Team, Position or
/// manager — the name key differs per kind (`name`, `title`,
/// `display_name`).
class OrgRef {
  const OrgRef({required this.publicId, required this.name});

  /// Null for a JSON null; otherwise strictly parsed.
  static OrgRef? fromNullable(Object? json, {String nameKey = 'name'}) {
    if (json == null) {
      return null;
    }

    final map = json as Map<String, dynamic>;

    return OrgRef(
      publicId: map['public_id'] as String,
      name: map[nameKey] as String,
    );
  }

  final String publicId;
  final String name;
}

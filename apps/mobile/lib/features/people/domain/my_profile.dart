import 'package:mobile/features/people/domain/staff_member.dart';

/// Typed view of `GET /api/v1/me/profile` (Phase 28, R-2,
/// docs/phases/V1_PHASE_28_DEFINITION.md §6.1): the signed-in person's own
/// account identity and their own Staff record. [staff] is null when no
/// Staff record is linked — a valid state, not an error.
class MyProfile {
  const MyProfile({required this.user, required this.staff});

  /// Parses the object under the response's `data` key.
  factory MyProfile.fromJson(Map<String, dynamic> json) {
    try {
      final staff = json['staff'];

      return MyProfile(
        user: ProfileUser.fromJson(json['user'] as Map<String, dynamic>),
        staff: staff == null
            ? null
            : StaffMember.fromJson(staff as Map<String, dynamic>),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected /me/profile response shape: $e');
    }
  }

  final ProfileUser user;

  /// Null when the account has no linked Staff record.
  final StaffMember? staff;

  bool get hasStaffProfile => staff != null;
}

/// The account part of [MyProfile]: the person's own login identity.
class ProfileUser {
  const ProfileUser({
    required this.publicId,
    required this.name,
    required this.email,
    this.role,
  });

  factory ProfileUser.fromJson(Map<String, dynamic> json) => ProfileUser(
    publicId: json['public_id'] as String,
    name: json['name'] as String,
    email: json['email'] as String,
    role: json['role'] as String?,
  );

  final String publicId;
  final String name;

  /// The login email (the person's own — safe to show them).
  final String email;

  /// `administrator` / `manager` / `staff`, or null for no role.
  final String? role;

  /// The role as shown on screen (spec §8), or null for no role.
  String? get roleLabel => switch (role) {
    'administrator' => 'Administrator',
    'manager' => 'Manager',
    'staff' => 'Staff',
    null => null,
    final other => other,
  };
}

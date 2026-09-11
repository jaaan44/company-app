/// Safe identity information for the authenticated user, matching the API's
/// `UserResource` shape — never an internal numeric id, password, or other
/// sensitive detail (see docs/04_API_CONVENTIONS.md, docs/05_SECURITY_MODEL.md).
class AuthUser {
  const AuthUser({
    required this.publicId,
    required this.name,
    required this.email,
    required this.status,
  });

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    return AuthUser(
      publicId: json['public_id'] as String,
      name: json['name'] as String,
      email: json['email'] as String,
      status: json['status'] as String,
    );
  }

  final String publicId;
  final String name;
  final String email;
  final String status;
}

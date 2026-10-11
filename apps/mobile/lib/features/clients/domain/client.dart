/// Typed views of the Phase 8 `ClientResource` (`GET /clients`,
/// `/clients/{id}`) and `ContactResource` (`GET /contacts?client=`) —
/// Phase 29C, docs/phases/V1_PHASE_29_DEFINITION.md §7.
///
/// Parsing is strict: a required field that is missing or of the wrong type,
/// or an unknown status, throws a [FormatException]; a nullable field stays
/// null. `notes` is deliberately not read (R-24).
library;

/// A client's or contact's status (Phase 8 `ClientStatus`/`ContactStatus`).
enum ClientStatus {
  active('active', 'Active'),
  inactive('inactive', 'Inactive');

  const ClientStatus(this.wireValue, this.label);

  final String wireValue;
  final String label;

  static ClientStatus fromWire(Object? value) => ClientStatus.values.firstWhere(
    (s) => s.wireValue == value,
    orElse: () => throw FormatException('Unknown client status: $value'),
  );
}

class Client {
  const Client({
    required this.publicId,
    required this.name,
    required this.status,
    this.clientCode,
    this.email,
    this.phone,
    this.website,
    this.addressLine1,
    this.addressLine2,
    this.city,
    this.stateProvince,
    this.postalCode,
    this.country,
  });

  factory Client.fromJson(Map<String, dynamic> json) {
    try {
      return Client(
        publicId: json['public_id'] as String,
        name: json['name'] as String,
        status: ClientStatus.fromWire(json['status']),
        clientCode: json['client_code'] as String?,
        email: json['email'] as String?,
        phone: json['phone'] as String?,
        website: json['website'] as String?,
        addressLine1: json['address_line1'] as String?,
        addressLine2: json['address_line2'] as String?,
        city: json['city'] as String?,
        stateProvince: json['state_province'] as String?,
        postalCode: json['postal_code'] as String?,
        country: json['country'] as String?,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected client shape: $e');
    }
  }

  final String publicId;
  final String name;
  final ClientStatus status;
  final String? clientCode;
  final String? email;
  final String? phone;
  final String? website;
  final String? addressLine1;
  final String? addressLine2;
  final String? city;
  final String? stateProvince;
  final String? postalCode;
  final String? country;

  bool get isInactive => status == ClientStatus.inactive;

  /// The address as display lines: the two street lines, then
  /// "city, state postal", then the country — blank parts dropped. Empty
  /// when no part is set.
  List<String> get addressLines {
    String? clean(String? s) => s == null || s.trim().isEmpty ? null : s.trim();
    final locality = [
      clean(city),
      [clean(stateProvince), clean(postalCode)].whereType<String>().join(' '),
    ].whereType<String>().where((s) => s.isNotEmpty).join(', ');

    return [
      clean(addressLine1),
      clean(addressLine2),
      if (locality.isNotEmpty) locality,
      clean(country),
    ].whereType<String>().toList(growable: false);
  }
}

/// One row of `GET /contacts?client=`.
class ClientContact {
  const ClientContact({
    required this.publicId,
    required this.fullName,
    required this.isPrimary,
    required this.status,
    this.jobTitle,
    this.email,
    this.phone,
  });

  factory ClientContact.fromJson(Map<String, dynamic> json) {
    try {
      return ClientContact(
        publicId: json['public_id'] as String,
        fullName: json['full_name'] as String,
        isPrimary: json['is_primary'] as bool,
        status: ClientStatus.fromWire(json['status']),
        jobTitle: json['job_title'] as String?,
        email: json['email'] as String?,
        phone: json['phone'] as String?,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected contact shape: $e');
    }
  }

  final String publicId;
  final String fullName;
  final bool isPrimary;
  final ClientStatus status;
  final String? jobTitle;
  final String? email;
  final String? phone;
}

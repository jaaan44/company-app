import 'package:mobile/features/people/domain/staff_member.dart';

/// One page of `GET /api/v1/staff` (Laravel's length-aware paginator:
/// `data` plus `meta.current_page` / `meta.last_page`).
class StaffDirectoryPage {
  const StaffDirectoryPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
  });

  /// Parses the whole response body (not just `data`).
  factory StaffDirectoryPage.fromJson(Map<String, dynamic> json) {
    try {
      final meta = json['meta'] as Map<String, dynamic>;

      return StaffDirectoryPage(
        items: (json['data'] as List<dynamic>)
            .map((s) => StaffMember.fromJson(s as Map<String, dynamic>))
            .toList(growable: false),
        currentPage: meta['current_page'] as int,
        lastPage: meta['last_page'] as int,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected /staff response shape: $e');
    }
  }

  final List<StaffMember> items;
  final int currentPage;
  final int lastPage;

  bool get hasMore => currentPage < lastPage;
}

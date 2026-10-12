/// Typed views of the Phase 14 announcements API as the mobile feed uses
/// it — Phase 32, docs/phases/V1_PHASE_32_DEFINITION.md (R-7).
///
/// Strict for the structure; nullable fields stay null.
library;

import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay, instant;

/// One `AnnouncementResource` as `/me/announcements` returns it.
class Announcement {
  const Announcement({
    required this.publicId,
    required this.title,
    required this.body,
    this.publishedAt,
    this.publishedBy,
    this.acknowledgedAt,
  });

  factory Announcement.fromJson(Map<String, dynamic> json) {
    try {
      final by = json['published_by'] as Map<String, dynamic>?;

      return Announcement(
        publicId: json['public_id'] as String,
        title: json['title'] as String,
        body: json['body'] as String,
        publishedAt: json['published_at'] == null
            ? null
            : instant(json['published_at']),
        publishedBy: by?['display_name'] as String?,
        acknowledgedAt: json['acknowledged_at'] == null
            ? null
            : instant(json['acknowledged_at']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected announcement shape: $e');
    }
  }

  final String publicId;
  final String title;

  /// Plain text.
  final String body;

  /// UTC.
  final DateTime? publishedAt;

  /// The publisher's display name, when they have a Staff record.
  final String? publishedBy;

  /// When I acknowledged it (UTC), or null.
  final DateTime? acknowledgedAt;

  bool get isAcknowledged => acknowledgedAt != null;
}

/// One page of `GET /me/announcements` with the company day (R-2b).
class AnnouncementsPage {
  const AnnouncementsPage({required this.companyDay, required this.page});

  factory AnnouncementsPage.fromJson(Map<String, dynamic> json) {
    try {
      return AnnouncementsPage(
        companyDay: ScheduleCompanyDay.fromJson(
          (json['meta'] as Map<String, dynamic>)['company_day']
              as Map<String, dynamic>,
        ),
        page: PagedResult.fromJson(json, Announcement.fromJson),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected announcements page shape: $e');
    }
  }

  final ScheduleCompanyDay companyDay;
  final PagedResult<Announcement> page;
}

/// Typed views of the Phase 15 notifications API — Phase 32,
/// docs/phases/V1_PHASE_32_DEFINITION.md (R-6).
library;

import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay, instant;

/// What a notification points at. An unknown source type is kept as
/// [unknown] (the notification only gets marked read).
enum NotificationSourceKind {
  announcement('announcement'),
  conversation('conversation'),
  unknown('');

  const NotificationSourceKind(this.wireValue);

  final String wireValue;

  static NotificationSourceKind fromWire(Object? value) {
    if (value is! String) {
      throw FormatException('Not a source type: $value');
    }

    return values.firstWhere(
      (k) => k != unknown && k.wireValue == value,
      orElse: () => unknown,
    );
  }
}

class NotificationSource {
  const NotificationSource({required this.kind, required this.publicId});

  final NotificationSourceKind kind;
  final String publicId;
}

/// One `NotificationResource`.
class AppNotification {
  const AppNotification({
    required this.publicId,
    required this.type,
    required this.title,
    required this.message,
    required this.createdAt,
    this.source,
    this.readAt,
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    try {
      final source = json['source'] as Map<String, dynamic>?;

      return AppNotification(
        publicId: json['public_id'] as String,
        type: json['type'] as String,
        title: json['title'] as String,
        message: json['message'] as String,
        source: source == null
            ? null
            : NotificationSource(
                kind: NotificationSourceKind.fromWire(source['type']),
                publicId: source['public_id'] as String,
              ),
        readAt: json['read_at'] == null ? null : instant(json['read_at']),
        createdAt: instant(json['created_at']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected notification shape: $e');
    }
  }

  final String publicId;

  /// `announcement_published`, `message_received`, or a newer type.
  final String type;
  final String title;
  final String message;
  final NotificationSource? source;

  /// UTC; null while unread.
  final DateTime? readAt;

  /// UTC.
  final DateTime createdAt;

  bool get isUnread => readAt == null;

  /// This notification, read at [at] (the server's answer replaces it).
  AppNotification markedRead(DateTime at) => AppNotification(
    publicId: publicId,
    type: type,
    title: title,
    message: message,
    source: source,
    readAt: readAt ?? at,
    createdAt: createdAt,
  );
}

/// One page of `GET /me/notifications` with the company day (R-2b).
class NotificationsPage {
  const NotificationsPage({required this.companyDay, required this.page});

  factory NotificationsPage.fromJson(Map<String, dynamic> json) {
    try {
      return NotificationsPage(
        companyDay: ScheduleCompanyDay.fromJson(
          (json['meta'] as Map<String, dynamic>)['company_day']
              as Map<String, dynamic>,
        ),
        page: PagedResult.fromJson(json, AppNotification.fromJson),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected notifications page shape: $e');
    }
  }

  final ScheduleCompanyDay companyDay;
  final PagedResult<AppNotification> page;
}

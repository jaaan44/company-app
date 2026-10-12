/// Typed views of the Phase 16 messaging API — Phase 32,
/// docs/phases/V1_PHASE_32_DEFINITION.md (R-3/R-4).
library;

import 'package:mobile/core/network/paged_result.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay, instant;

/// The API's limit on a message (Phase 16).
abstract final class MessageLimits {
  static const maxBodyLength = 4000;
}

enum ConversationKind {
  direct('direct'),
  group('group'),
  project('project'),
  unknown('');

  const ConversationKind(this.wireValue);

  final String wireValue;

  static ConversationKind fromWire(Object? value) {
    if (value is! String) {
      throw FormatException('Not a conversation type: $value');
    }

    return values.firstWhere(
      (k) => k != unknown && k.wireValue == value,
      orElse: () => unknown,
    );
  }
}

/// A `{public_id, name}` person.
class ChatPerson {
  const ChatPerson({required this.publicId, required this.name});

  factory ChatPerson.fromJson(Map<String, dynamic> json) => ChatPerson(
    publicId: json['public_id'] as String,
    name: json['name'] as String,
  );

  final String publicId;
  final String name;
}

/// One `MessageResource` (also a conversation's `last_message`).
class ChatMessage {
  const ChatMessage({
    required this.publicId,
    required this.body,
    required this.sender,
    required this.createdAt,
  });

  factory ChatMessage.fromJson(Map<String, dynamic> json) {
    try {
      return ChatMessage(
        publicId: json['public_id'] as String,
        body: json['body'] as String,
        sender: ChatPerson.fromJson(json['sender'] as Map<String, dynamic>),
        createdAt: instant(json['created_at']),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected message shape: $e');
    }
  }

  final String publicId;
  final String body;
  final ChatPerson sender;

  /// UTC.
  final DateTime createdAt;
}

/// One `ConversationResource`.
class Conversation {
  const Conversation({
    required this.publicId,
    required this.kind,
    required this.members,
    required this.unreadCount,
    this.name,
    this.projectName,
    this.lastMessage,
  });

  factory Conversation.fromJson(Map<String, dynamic> json) {
    try {
      final project = json['project'] as Map<String, dynamic>?;
      final last = json['last_message'] as Map<String, dynamic>?;

      return Conversation(
        publicId: json['public_id'] as String,
        kind: ConversationKind.fromWire(json['type']),
        name: json['name'] as String?,
        projectName: project?['name'] as String?,
        members: List.unmodifiable(
          (json['members'] as List<dynamic>).map(
            (m) => ChatPerson.fromJson(
              (m as Map<String, dynamic>)['staff'] as Map<String, dynamic>,
            ),
          ),
        ),
        unreadCount: (json['unread_count'] as int?) ?? 0,
        lastMessage: last == null ? null : ChatMessage.fromJson(last),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected conversation shape: $e');
    }
  }

  final String publicId;
  final ConversationKind kind;

  /// A group's name.
  final String? name;

  /// A project chat's project name.
  final String? projectName;
  final List<ChatPerson> members;
  final int unreadCount;
  final ChatMessage? lastMessage;

  /// R-3: the group name, the project name, or — for a direct chat — the
  /// other person (everyone but [myStaffId]).
  String titleFor(String? myStaffId) {
    switch (kind) {
      case ConversationKind.group:
        return name ?? 'Group';
      case ConversationKind.project:
        return projectName ?? name ?? 'Project chat';
      case ConversationKind.direct:
      case ConversationKind.unknown:
        final others = members.where((m) => m.publicId != myStaffId);
        if (others.isEmpty) {
          return name ?? 'Conversation';
        }

        return others.map((m) => m.name).join(', ');
    }
  }

  /// Group and project chats name each sender (R-4).
  bool get showsSenderNames =>
      kind == ConversationKind.group || kind == ConversationKind.project;
}

/// One page of `GET /conversations` or `/conversations/{id}/messages`,
/// with the company day (R-2b).
class ChatPage<T> {
  const ChatPage({required this.companyDay, required this.page});

  factory ChatPage.fromJson(
    Map<String, dynamic> json,
    T Function(Map<String, dynamic> json) item,
  ) {
    try {
      return ChatPage(
        companyDay: ScheduleCompanyDay.fromJson(
          (json['meta'] as Map<String, dynamic>)['company_day']
              as Map<String, dynamic>,
        ),
        page: PagedResult.fromJson(json, item),
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected page shape: $e');
    }
  }

  final ScheduleCompanyDay companyDay;
  final PagedResult<T> page;
}

import 'package:flutter/material.dart';

import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/messages/domain/conversation.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay, shiftDate;

/// Shared pieces of the Messages screens (Phase 32).

const messagesPath = '/messages';
const newMessagePath = '/messages/new';

String chatPath(String publicId) =>
    '/messages/${Uri.encodeComponent(publicId)}';

/// What the list passes to a chat, so it opens without re-reading.
class ChatArgs {
  const ChatArgs({this.initial, this.myStaffId});

  final Conversation? initial;
  final String? myStaffId;
}

String _date(DateTime local) =>
    '${local.year.toString().padLeft(4, '0')}-'
    '${local.month.toString().padLeft(2, '0')}-'
    '${local.day.toString().padLeft(2, '0')}';

/// The company date (`YYYY-MM-DD`) of [instant].
String companyDateOf(DateTime instant, ScheduleCompanyDay day) =>
    _date(day.toCompanyTime(instant));

/// A list row's time (R-3): "09:12" today, "Yesterday", "Mon 12 Oct",
/// or "12 Oct 2025" in another year — company time.
String inboxTimeLabel(DateTime instant, ScheduleCompanyDay day) {
  final local = day.toCompanyTime(instant);
  final date = _date(local);
  if (date == day.date) {
    return formatTime(local);
  }
  if (date == shiftDate(day.date, -1)) {
    return 'Yesterday';
  }

  return local.year == DateTime.parse(day.date).year
      ? formatDayMonth(local)
      : formatDate(local);
}

/// A day separator in a chat (R-4): "Today", "Yesterday", "Mon 12 Oct".
String chatDayLabel(String date, ScheduleCompanyDay day) {
  if (date == day.date) {
    return 'Today';
  }
  if (date == shiftDate(day.date, -1)) {
    return 'Yesterday';
  }
  final d = DateTime.parse(date);

  return d.year == DateTime.parse(day.date).year
      ? formatDayMonth(d)
      : formatDate(d);
}

/// R-3: "You: …" for mine, "Grace: …" in a group, the text alone in a
/// direct chat from the other person.
String lastMessagePreview(Conversation c, String? myStaffId) {
  final m = c.lastMessage;
  if (m == null) {
    return 'No messages yet';
  }
  final text = m.body.replaceAll(RegExp(r'\s+'), ' ').trim();
  if (m.sender.publicId == myStaffId) {
    return 'You: $text';
  }
  if (c.showsSenderNames) {
    return '${m.sender.name.split(' ').first}: $text';
  }

  return text;
}

/// An unread count badge, announced with its meaning.
class UnreadBadge extends StatelessWidget {
  const UnreadBadge(this.count, {super.key});

  final int count;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Semantics(
      label: '$count unread',
      excludeSemantics: true,
      child: Container(
        constraints: const BoxConstraints(minWidth: 24),
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
        decoration: BoxDecoration(
          color: scheme.primary,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Text(
          count > 99 ? '99+' : '$count',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.labelMedium
              ?.copyWith(color: scheme.onPrimary),
        ),
      ),
    );
  }
}

/// The centred no-profile message (R-9).
class InboxNoProfile extends StatelessWidget {
  const InboxNoProfile({super.key, this.keyName = 'inbox-no-profile'});

  final String keyName;

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        'No staff profile is linked to this account.',
        key: Key(keyName),
        textAlign: TextAlign.center,
      ),
    ),
  );
}

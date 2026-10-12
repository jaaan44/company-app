import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:mobile/features/home/presentation/home_formatting.dart';
import 'package:mobile/features/messages/data/messages_api_client.dart';
import 'package:mobile/features/messages/domain/conversation.dart';
import 'package:mobile/features/messages/presentation/messages_widgets.dart';
import 'package:mobile/features/messages/state/chat_controller.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';

/// One conversation (Phase 32, R-4/R-5): messages oldest to newest with
/// day separators, mine on the right, **Load earlier messages** at the
/// top, a composer at the bottom. While the screen is shown and the app is
/// in the foreground it checks for new messages every [pollInterval].
class ChatPage extends StatefulWidget {
  const ChatPage({
    super.key,
    required this.messagesApiClient,
    required this.inboxChanges,
    required this.publicId,
    this.args,
    this.pollInterval = const Duration(seconds: 15),
  });

  final MessagesApiClient messagesApiClient;
  final InboxChanges inboxChanges;
  final String publicId;
  final ChatArgs? args;
  final Duration pollInterval;

  @override
  State<ChatPage> createState() => _ChatPageState();
}

class _ChatPageState extends State<ChatPage> {
  late final ChatController _controller = ChatController(
    widget.messagesApiClient,
    widget.publicId,
    initial: widget.args?.initial,
    myStaffId: widget.args?.myStaffId,
    changes: widget.inboxChanges,
  );
  final TextEditingController _text = TextEditingController();
  Timer? _timer;
  late final AppLifecycleListener _lifecycle;
  bool _foreground = true;

  @override
  void initState() {
    super.initState();
    _controller.load();
    _lifecycle = AppLifecycleListener(
      onStateChange: (state) =>
          _foreground = state == AppLifecycleState.resumed,
    );
    _timer = Timer.periodic(widget.pollInterval, (_) => _poll());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _lifecycle.dispose();
    _controller.dispose();
    _text.dispose();
    super.dispose();
  }

  /// R-5: only while this screen is the one shown, in the foreground.
  void _poll() {
    final route = ModalRoute.of(context);
    if (!mounted || !_foreground || (route != null && !route.isCurrent)) {
      return;
    }
    _controller.poll();
  }

  Future<void> _send() async {
    if (await _controller.send(_text.text) && mounted) {
      _text.clear();
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _controller,
      builder: (context, _) => PopScope(
        // Leaving wouldn't stop a message already sent (30B Gate 4).
        canPop: !_controller.isSending,
        child: Scaffold(
          appBar: AppBar(title: Text(_controller.title)),
          body: _body(context),
        ),
      ),
    );
  }

  Widget _body(BuildContext context) {
    switch (_controller.status) {
      case ChatStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case ChatStatus.noProfile:
        return const InboxNoProfile(keyName: 'chat-no-profile');
      case ChatStatus.error:
        return PeopleErrorView(
          message:
              _controller.errorMessage ??
              'Something went wrong loading this conversation.',
          onRetry: _controller.load,
        );
      case ChatStatus.loaded:
        break;
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        final padding = peopleListPadding(constraints);

        return Column(
          children: [
            Expanded(child: _messageList(context, padding)),
            _composer(context, padding),
          ],
        );
      },
    );
  }

  Widget _messageList(BuildContext context, EdgeInsets padding) {
    final day = _controller.companyDay!;
    final messages = _controller.messages;
    final conversation = _controller.conversation;
    final names = conversation?.showsSenderNames ?? false;

    // Newest at the bottom: a reversed list, built from the end.
    final rows = <Widget>[];
    for (var i = messages.length - 1; i >= 0; i--) {
      final m = messages[i];
      final date = companyDateOf(m.createdAt, day);
      rows.add(
        _Bubble(
          key: Key('chat-message-${m.publicId}'),
          message: m,
          mine: _controller.isMine(m),
          showName: names && !_controller.isMine(m),
          time: formatTime(day.toCompanyTime(m.createdAt)),
        ),
      );
      final previous = i == 0
          ? null
          : companyDateOf(messages[i - 1].createdAt, day);
      if (previous != date) {
        rows.add(_DaySeparator(chatDayLabel(date, day)));
      }
    }
    if (_controller.hasOlder) {
      rows.add(
        Padding(
          padding: const EdgeInsets.all(8),
          child: Center(
            child: _controller.isLoadingOlder
                ? const CircularProgressIndicator()
                : TextButton(
                    key: const Key('chat-load-older'),
                    onPressed: _controller.loadOlder,
                    child: Text(
                      _controller.loadOlderFailed
                          ? "Couldn't load earlier messages. Tap to try again."
                          : 'Load earlier messages',
                    ),
                  ),
          ),
        ),
      );
    }

    if (messages.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'No messages yet. Say hello.',
            key: Key('chat-empty'),
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    return ListView(
      key: const Key('chat-list'),
      reverse: true,
      padding: padding,
      children: rows,
    );
  }

  Widget _composer(BuildContext context, EdgeInsets padding) {
    final theme = Theme.of(context);
    final error = _controller.sendError;
    final sending = _controller.isSending;

    return SafeArea(
      top: false,
      child: Material(
        color: theme.colorScheme.surfaceContainer,
        child: Padding(
          padding: EdgeInsets.fromLTRB(padding.left, 8, padding.right, 8),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (error != null)
                Semantics(
                  liveRegion: true,
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Text(
                      error,
                      key: const Key('chat-send-error'),
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.error,
                      ),
                    ),
                  ),
                ),
              Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Expanded(
                    child: TextField(
                      key: const Key('chat-input'),
                      controller: _text,
                      enabled: !sending,
                      minLines: 1,
                      maxLines: 5,
                      maxLength: MessageLimits.maxBodyLength,
                      maxLengthEnforcement: MaxLengthEnforcement.enforced,
                      keyboardType: TextInputType.multiline,
                      textCapitalization: TextCapitalization.sentences,
                      decoration: const InputDecoration(
                        hintText: 'Message',
                        border: OutlineInputBorder(),
                        counterText: '',
                        isDense: true,
                      ),
                      onChanged: (_) => _controller.clearSendError(),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton.filled(
                    key: const Key('chat-send'),
                    tooltip: 'Send',
                    onPressed: sending ? null : _send,
                    icon: sending
                        ? const SizedBox.square(
                            dimension: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.send),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _DaySeparator extends StatelessWidget {
  const _DaySeparator(this.label);

  final String label;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Center(
        child: Semantics(
          header: true,
          child: Text(
            label,
            style: theme.textTheme.labelMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({
    super.key,
    required this.message,
    required this.mine,
    required this.showName,
    required this.time,
  });

  final ChatMessage message;
  final bool mine;
  final bool showName;
  final String time;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final background = mine
        ? scheme.primaryContainer
        : scheme.surfaceContainerHighest;
    final foreground = mine ? scheme.onPrimaryContainer : scheme.onSurface;

    return Semantics(
      label: '${mine ? 'You' : message.sender.name}, $time',
      child: Align(
        alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxWidth: MediaQuery.sizeOf(context).width * 0.8,
          ),
          child: Container(
            margin: const EdgeInsets.symmetric(vertical: 3),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: background,
              borderRadius: BorderRadius.circular(16),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (showName)
                  Text(
                    message.sender.name,
                    style: theme.textTheme.labelMedium?.copyWith(
                      color: scheme.primary,
                    ),
                  ),
                SelectableText(
                  message.body,
                  style: theme.textTheme.bodyLarge?.copyWith(color: foreground),
                ),
                const SizedBox(height: 2),
                Text(
                  time,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: foreground.withValues(alpha: 0.75),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

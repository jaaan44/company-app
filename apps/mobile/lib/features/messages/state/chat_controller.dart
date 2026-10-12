import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/messages/data/messages_api_client.dart';
import 'package:mobile/features/messages/domain/conversation.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/schedule/domain/schedule.dart'
    show ScheduleCompanyDay;

enum ChatStatus { loading, loaded, noProfile, error }

/// One conversation (Phase 32, R-4/R-5).
///
/// - [load] reads my Staff id (unless given), the conversation (unless the
///   list gave it) and the newest page of messages, then marks it read.
/// - [messages] are oldest first; [loadOlder] adds the previous page at
///   the top. Pages are numbered from the newest, so new messages shift
///   them: the overlap is removed by id, nothing is skipped.
/// - [poll] (every 15 s while the screen is shown, R-5) adds new messages
///   at the bottom and marks them read. If a whole page is new it reads
///   further pages, up to [maxCatchUpPages].
/// - [send] sends once, never retried. On success the server's message is
///   added; on failure [sendError] is set and the page keeps the text.
class ChatController extends ChangeNotifier {
  ChatController(
    this._client,
    this.publicId, {
    Conversation? initial,
    String? myStaffId,
    this.changes,
  }) : _conversation = initial,
       _myStaffId = myStaffId; // ignore: prefer_initializing_formals

  /// How many extra pages one poll may read to close a gap.
  static const maxCatchUpPages = 5;

  final MessagesApiClient _client;
  final String publicId;
  final InboxChanges? changes;

  ChatStatus _status = ChatStatus.loading;
  Conversation? _conversation;
  String? _myStaffId;
  ScheduleCompanyDay? _companyDay;
  String? _errorMessage;

  /// Oldest first.
  final List<ChatMessage> _messages = [];
  final Set<String> _ids = {};
  int _nextOlderPage = 2;
  bool _hasOlder = false;
  bool _isLoadingOlder = false;
  bool _loadOlderFailed = false;
  bool _isSending = false;
  String? _sendError;
  bool _isPolling = false;
  bool _disposed = false;

  ChatStatus get status => _status;
  Conversation? get conversation => _conversation;
  String? get myStaffId => _myStaffId;
  ScheduleCompanyDay? get companyDay => _companyDay;
  String? get errorMessage => _errorMessage;
  List<ChatMessage> get messages => List.unmodifiable(_messages);
  bool get hasOlder => _hasOlder;
  bool get isLoadingOlder => _isLoadingOlder;
  bool get loadOlderFailed => _loadOlderFailed;
  bool get isSending => _isSending;
  String? get sendError => _sendError;

  String get title => _conversation?.titleFor(_myStaffId) ?? 'Conversation';

  bool isMine(ChatMessage m) => m.sender.publicId == _myStaffId;

  Future<void> load() async {
    _status = ChatStatus.loading;
    _errorMessage = null;
    _notify();

    try {
      _myStaffId ??= await _client.fetchMyStaffPublicId();
      if (_myStaffId == null) {
        _status = ChatStatus.noProfile;

        return;
      }
      _conversation ??= await _client.fetchConversation(publicId);
      final page = await _client.fetchMessages(publicId);
      _messages.clear();
      _ids.clear();
      _companyDay = page.companyDay;
      _addNewestFirst(page.page.items, atTop: false);
      _hasOlder = page.page.hasMore;
      _nextOlderPage = page.page.currentPage + 1;
      _status = ChatStatus.loaded;
      if (_conversation!.unreadCount > 0) {
        await _markRead();
      }
    } on ApiSessionExpiredException {
      // The app is returning to Login.
    } on ApiForbiddenException {
      _status = ChatStatus.noProfile;
    } on ApiException catch (e) {
      _status = ChatStatus.error;
      _errorMessage = switch (e) {
        ApiNetworkException() =>
          "Couldn't load this conversation. Check your connection.",
        ApiRequestException(statusCode: 404) =>
          'This conversation is no longer available.',
        _ => 'Something went wrong loading this conversation.',
      };
    } finally {
      _notify();
    }
  }

  Future<void> loadOlder() async {
    if (!_hasOlder || _isLoadingOlder || _status != ChatStatus.loaded) {
      return;
    }
    _isLoadingOlder = true;
    _loadOlderFailed = false;
    _notify();
    try {
      final page = await _client.fetchMessages(publicId, page: _nextOlderPage);
      _addNewestFirst(page.page.items, atTop: true);
      _hasOlder = page.page.hasMore;
      _nextOlderPage = page.page.currentPage + 1;
    } on ApiSessionExpiredException {
      // The app is returning to Login.
    } on ApiException {
      _loadOlderFailed = true;
    } finally {
      _isLoadingOlder = false;
      _notify();
    }
  }

  /// Checks for new messages. Returns how many were added. Failures are
  /// quiet (the next poll tries again).
  Future<int> poll() async {
    if (_isPolling || _status != ChatStatus.loaded) {
      return 0;
    }
    _isPolling = true;
    var added = 0;
    try {
      final fresh = <ChatMessage>[];
      for (var page = 1; page <= 1 + maxCatchUpPages; page++) {
        final result = await _client.fetchMessages(publicId, page: page);
        _companyDay = result.companyDay;
        final unseen = result.page.items
            .where((m) => !_ids.contains(m.publicId))
            .toList();
        fresh.addAll(unseen);
        // Stop once a page overlaps what we have, or there's no more.
        if (unseen.length < result.page.items.length || !result.page.hasMore) {
          break;
        }
      }
      // All pages first, then one ordered insert.
      _insertNewer(fresh);
      added = fresh.length;
      if (added > 0) {
        await _markRead();
      }
    } on ApiException {
      // Quiet: the next poll tries again.
    } finally {
      _isPolling = false;
      if (added > 0) {
        _notify();
      }
    }

    return added;
  }

  /// Sends [text] once. Returns true when the server confirmed it.
  Future<bool> send(String text) async {
    final body = text.trim();
    if (_isSending || _status != ChatStatus.loaded || body.isEmpty) {
      return false;
    }
    if (body.length > MessageLimits.maxBodyLength) {
      _sendError =
          'A message can be at most ${MessageLimits.maxBodyLength} characters.';
      _notify();

      return false;
    }
    _isSending = true;
    _sendError = null;
    _notify();

    try {
      final sent = await _client.send(publicId, body);
      _insertNewer([sent]);
      changes?.record();

      return true;
    } on ApiSessionExpiredException {
      return false;
    } on ApiValidationException catch (e) {
      _sendError = e.firstErrorFor('body') ?? e.message;

      return false;
    } on ApiException catch (e) {
      _sendError = switch (e) {
        ApiRequestException(statusCode: 404) =>
          'This conversation is no longer available.',
        _ => "Couldn't send. Try again.",
      };

      return false;
    } finally {
      _isSending = false;
      _notify();
    }
  }

  void clearSendError() {
    if (_sendError != null) {
      _sendError = null;
      _notify();
    }
  }

  Future<void> _markRead() async {
    try {
      await _client.markRead(publicId);
      changes?.record();
    } on ApiException {
      // Quiet: reading again on the next open or poll is harmless.
    }
  }

  /// [newestFirst] is a server page. At the top (older) or appended.
  void _addNewestFirst(List<ChatMessage> newestFirst, {required bool atTop}) {
    final oldestFirst = newestFirst.reversed
        .where((m) => _ids.add(m.publicId))
        .toList();
    if (atTop) {
      _messages.insertAll(0, oldestFirst);
    } else {
      _messages.addAll(oldestFirst);
    }
  }

  /// Newer messages (any order), added at the bottom in time order.
  void _insertNewer(List<ChatMessage> fresh) {
    final sorted = fresh.where((m) => _ids.add(m.publicId)).toList()
      ..sort((a, b) {
        final byTime = a.createdAt.compareTo(b.createdAt);

        // ULIDs sort by creation time too: a stable second key.
        return byTime != 0 ? byTime : a.publicId.compareTo(b.publicId);
      });
    _messages.addAll(sorted);
  }

  void _notify() {
    if (!_disposed) {
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}

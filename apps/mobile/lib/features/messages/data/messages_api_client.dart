import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/messages/domain/conversation.dart';

/// The messaging calls (Phase 32, R-3…R-5) through the shared
/// [ApiClient]. A `403` that reaches a caller means no Staff record is
/// linked (R-9); a conversation I'm not in is `404`.
class MessagesApiClient {
  MessagesApiClient(this._apiClient);

  static const conversationPageSize = 20;
  static const messagePageSize = 30;

  final ApiClient _apiClient;

  /// `GET /conversations` — newest activity first.
  Future<ChatPage<Conversation>> fetchConversations({int page = 1}) async {
    final body = await _apiClient.getJson(
      _paged('/conversations', page, conversationPageSize),
    );

    return _parse(() => ChatPage.fromJson(body, Conversation.fromJson));
  }

  /// `GET /conversations/{id}`.
  Future<Conversation> fetchConversation(String publicId) async =>
      _conversation(await _apiClient.getJson(_path(publicId)));

  /// `GET /conversations/{id}/messages` — newest first, [perPage] a page.
  Future<ChatPage<ChatMessage>> fetchMessages(
    String publicId, {
    int page = 1,
    int perPage = messagePageSize,
  }) async {
    final body = await _apiClient.getJson(
      _paged('${_path(publicId)}/messages', page, perPage),
    );

    return _parse(() => ChatPage.fromJson(body, ChatMessage.fromJson));
  }

  /// `POST /conversations/{id}/messages` — sent once, never retried.
  Future<ChatMessage> send(String publicId, String body) async {
    final response = await _apiClient.postJson(
      '${_path(publicId)}/messages',
      body: {'body': body.trim()},
    );

    return _parse(
      () => ChatMessage.fromJson(response!['data'] as Map<String, dynamic>),
    );
  }

  /// `POST /conversations/{id}/read` — also reads my message notifications
  /// for it (R-2d).
  Future<void> markRead(String publicId) async {
    await _apiClient.postJson('${_path(publicId)}/read');
  }

  /// `POST /conversations/direct` — find-or-create my chat with [staffId].
  Future<Conversation> openDirect(String staffId) async => _conversation(
    await _apiClient.postJson(
      '/conversations/direct',
      body: {'staff_id': staffId},
    ),
  );

  /// My own Staff record's public id from `GET /me/profile`, or null when
  /// none is linked — to tell my messages from others'.
  Future<String?> fetchMyStaffPublicId() async {
    final body = await _apiClient.getJson('/me/profile');

    return _parse(() {
      final staff = (body['data'] as Map<String, dynamic>)['staff'];

      return staff == null
          ? null
          : (staff as Map<String, dynamic>)['public_id'] as String;
    });
  }

  String _path(String id) => '/conversations/${Uri.encodeComponent(id)}';

  String _paged(String path, int page, int perPage) => Uri(
    path: path,
    queryParameters: {'per_page': '$perPage', 'page': '$page'},
  ).toString();

  Conversation _conversation(Map<String, dynamic>? body) => _parse(() {
    final data = body?['data'];
    if (data is! Map<String, dynamic>) {
      throw const FormatException('Missing data object');
    }

    return Conversation.fromJson(data);
  });
}

T _parse<T>(T Function() parse) {
  try {
    return parse();
  } on FormatException {
    throw const ApiRequestException('Something went wrong. Please try again.');
  } on TypeError {
    throw const ApiRequestException('Something went wrong. Please try again.');
  }
}

import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import 'package:mobile/core/config/app_config.dart';
import 'package:mobile/core/network/api_exception.dart';

/// The result of re-checking a session with `GET /auth/me` after a 403.
enum SessionValidity {
  /// `/auth/me` returned 200 — the token is still valid.
  valid,

  /// `/auth/me` returned 401 — the token is no longer valid.
  invalid,

  /// Anything else (403, 5xx, network failure, …) — no evidence either
  /// way, so the session is kept.
  undetermined,
}

/// What [ApiClient] needs from the app's session owner (implemented by
/// `AuthController`). Kept as an interface here so `core/` doesn't depend
/// on a feature folder.
abstract interface class ApiSession {
  /// The bearer token of the current authenticated session, or null.
  String? get currentToken;

  /// Re-checks [token] with `GET /auth/me`. Must not itself go through
  /// [ApiClient], so a 403 can never trigger a recursive re-check.
  Future<SessionValidity> verifySession(String token);

  /// Ends the local session, but only if [tokenUsed] still belongs to the
  /// current session — a late response for an older token must never sign
  /// out a newer login.
  Future<void> expireSession({required String? tokenUsed});
}

/// Minimal authenticated client for `/api/v1` (Phase 27,
/// docs/phases/V1_PHASE_27_DEFINITION.md §7; writes added in Phase 29A,
/// docs/phases/V1_PHASE_29_DEFINITION.md §5.2). Attaches the current bearer
/// token and centralizes the session rules every authenticated request
/// shares — reads and writes alike — so feature code only ever sees an
/// [ApiException]:
///
/// - **401** → the session has ended: [ApiSession.expireSession] (local
///   invalidation only — never `/auth/logout`), then
///   [ApiSessionExpiredException].
/// - **403** → never session-ending by itself (the API uses 403 both for a
///   deactivated account and for ordinary refusals, and message text is
///   never parsed). One `/auth/me` check decides: 401 ends the session;
///   200 or anything inconclusive keeps it and reports the original 403 as
///   [ApiForbiddenException].
/// - **422** → [ApiValidationException], with the server's message and
///   per-field errors.
/// - **204** → success with no body.
///
/// Request bodies are JSON. **Nothing is ever retried automatically** — in
/// particular a write is sent at most once, even when a 403 triggers the
/// `/auth/me` re-check. `AuthApiClient` (login/me/logout) stays separate.
class ApiClient {
  ApiClient({
    required ApiSession session,
    http.Client? httpClient,
    String? baseUrl,
  }) : _session = session, // ignore: prefer_initializing_formals
       _httpClient = httpClient ?? http.Client(),
       _baseUrl = baseUrl ?? AppConfig.apiBaseUrl;

  final ApiSession _session;
  final http.Client _httpClient;
  final String _baseUrl;

  /// GETs [path] (relative to the API base URL, e.g. `/me/home`) and
  /// returns the decoded JSON object body.
  Future<Map<String, dynamic>> getJson(String path) async {
    final body = await _send('GET', path);
    if (body == null) {
      // A read always has a body; a bodiless 204 is not the contract.
      throw const ApiRequestException(
        'Something went wrong. Please try again.',
        statusCode: 204,
      );
    }

    return body;
  }

  /// POSTs [body] as JSON to [path]. Returns the decoded JSON object body,
  /// or null for a `204 No Content`.
  Future<Map<String, dynamic>?> postJson(
    String path, {
    Map<String, dynamic>? body,
  }) => _send('POST', path, body: body ?? const {});

  /// PATCHes [body] as JSON to [path]. Returns the decoded JSON object body,
  /// or null for a `204 No Content`.
  Future<Map<String, dynamic>?> patchJson(
    String path,
    Map<String, dynamic> body,
  ) => _send('PATCH', path, body: body);

  /// DELETEs [path]. Any 2xx (usually `204`) is success.
  Future<void> delete(String path) async {
    await _send('DELETE', path, allowAnyBody: true);
  }

  /// POSTs one file as `multipart/form-data` (Phase 33, R-9): [bytes]
  /// under [field] with [filename], plus [fields]. The server decides the
  /// type from the content itself, so none is declared. Same session
  /// rules; sent once, never retried. Returns the decoded JSON object body.
  Future<Map<String, dynamic>?> postMultipart(
    String path, {
    required String field,
    required List<int> bytes,
    required String filename,
    Map<String, String> fields = const {},
  }) async {
    final response = await _exchange(path, (token) {
      final request = http.MultipartRequest('POST', Uri.parse('$_baseUrl$path'))
        ..headers.addAll(_headers(token))
        ..fields.addAll(fields)
        ..files.add(
          http.MultipartFile.fromBytes(field, bytes, filename: filename),
        );

      return request;
    });

    return _jsonResult(response, allowAnyBody: false);
  }

  /// GETs [path] as bytes (Phase 33, R-9: an authorized attachment
  /// download). Same session rules. The bytes stay in memory; nothing is
  /// written to the device.
  Future<Uint8List> getBytes(String path) async {
    final response = await _exchange(
      path,
      (token) =>
          http.Request('GET', Uri.parse('$_baseUrl$path'))
            ..headers.addAll(_headers(token, accept: '*/*')),
    );

    return response.bodyBytes;
  }

  Map<String, String> _headers(
    String token, {
    String accept = 'application/json',
  }) => {'Accept': accept, 'Authorization': 'Bearer $token'};

  /// Sends one JSON request and applies the shared response rules. Returns
  /// the decoded JSON object body, or null for a 204 (or, with
  /// [allowAnyBody], for any successful body that is not a JSON object).
  Future<Map<String, dynamic>?> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    bool allowAnyBody = false,
  }) async {
    final response = await _exchange(path, (token) {
      final request = http.Request(method, Uri.parse('$_baseUrl$path'))
        ..headers.addAll(_headers(token));
      if (body != null) {
        request.headers['Content-Type'] = 'application/json';
        request.body = jsonEncode(body);
      }

      return request;
    });

    return _jsonResult(response, allowAnyBody: allowAnyBody);
  }

  Map<String, dynamic>? _jsonResult(
    http.Response response, {
    required bool allowAnyBody,
  }) {
    final status = response.statusCode;
    if (status == 204) {
      return null;
    }

    final decoded = _tryDecode(response);
    if (decoded == null && !allowAnyBody) {
      throw ApiRequestException(
        'Something went wrong. Please try again.',
        statusCode: status,
      );
    }

    return decoded;
  }

  /// Sends the request [build] makes with the current token, once, and
  /// applies every shared rule (401, 403 re-check, 422, 5xx, other).
  /// Returns the successful (2xx) response.
  Future<http.Response> _exchange(
    String path,
    http.BaseRequest Function(String token) build,
  ) async {
    final token = _session.currentToken;

    if (token == null) {
      await _session.expireSession(tokenUsed: null);
      throw const ApiSessionExpiredException();
    }

    final http.Response response;
    try {
      response = await http.Response.fromStream(
        await _httpClient.send(build(token)),
      );
    } on Exception {
      throw const ApiNetworkException();
    }

    final status = response.statusCode;

    if (status >= 200 && status < 300) {
      return response;
    }

    if (status == 401) {
      await _session.expireSession(tokenUsed: token);
      throw const ApiSessionExpiredException();
    }

    if (status == 403) {
      final validity = await _session.verifySession(token);

      if (validity == SessionValidity.invalid) {
        await _session.expireSession(tokenUsed: token);
        throw const ApiSessionExpiredException();
      }

      throw ApiForbiddenException(
        _messageOf(response) ?? 'You do not have access to this.',
      );
    }

    if (status == 422) {
      throw ApiValidationException(
        _messageOf(response) ?? 'Please check the details and try again.',
        fieldErrors: _fieldErrorsOf(response),
      );
    }

    if (status >= 500) {
      throw ApiServerException(status);
    }

    throw ApiRequestException(
      _messageOf(response) ?? 'Something went wrong. Please try again.',
      statusCode: status,
    );
  }

  Map<String, dynamic>? _tryDecode(http.Response response) {
    try {
      final decoded = jsonDecode(response.body);

      return decoded is Map<String, dynamic> ? decoded : null;
    } on FormatException {
      return null;
    }
  }

  String? _messageOf(http.Response response) {
    final message = _tryDecode(response)?['message'];

    return message is String && message.isNotEmpty ? message : null;
  }

  /// Laravel's `errors` object (`{"field": ["message", …]}`). Anything that
  /// isn't a list of strings is skipped rather than failing the parse.
  Map<String, List<String>> _fieldErrorsOf(http.Response response) {
    final errors = _tryDecode(response)?['errors'];
    if (errors is! Map<String, dynamic>) {
      return const {};
    }

    return Map.unmodifiable({
      for (final entry in errors.entries)
        if (entry.value is List)
          entry.key: List<String>.unmodifiable(
            (entry.value as List).whereType<String>(),
          ),
    });
  }
}

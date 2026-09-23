import 'dart:convert';

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
/// docs/phases/V1_PHASE_27_DEFINITION.md §7). Attaches the current bearer
/// token and centralizes the session rules every authenticated request
/// shares, so feature code only ever sees an [ApiException]:
///
/// - **401** → the session has ended: [ApiSession.expireSession] (local
///   invalidation only — never `/auth/logout`), then
///   [ApiSessionExpiredException].
/// - **403** → never session-ending by itself (the API uses 403 both for a
///   deactivated account and for ordinary refusals, and message text is
///   never parsed). One `/auth/me` check decides: 401 ends the session;
///   200 or anything inconclusive keeps it and reports the original 403 as
///   [ApiForbiddenException].
///
/// GET only for now — other verbs are added when a phase needs them.
/// `AuthApiClient` (login/me/logout) stays separate.
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
    final token = _session.currentToken;

    if (token == null) {
      await _session.expireSession(tokenUsed: null);
      throw const ApiSessionExpiredException();
    }

    final http.Response response;
    try {
      response = await _httpClient.get(
        Uri.parse('$_baseUrl$path'),
        headers: {
          'Accept': 'application/json',
          'Authorization': 'Bearer $token',
        },
      );
    } on Exception {
      throw const ApiNetworkException();
    }

    final status = response.statusCode;

    if (status >= 200 && status < 300) {
      final decoded = _tryDecode(response);
      if (decoded == null) {
        throw ApiRequestException(
          'Something went wrong. Please try again.',
          statusCode: status,
        );
      }

      return decoded;
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
}

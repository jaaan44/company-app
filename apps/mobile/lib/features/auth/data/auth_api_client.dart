import 'dart:convert';

import 'package:http/http.dart' as http;

import 'package:mobile/core/config/app_config.dart';
import 'package:mobile/features/auth/domain/auth_user.dart';

/// Thrown for any login/me/logout failure with a message safe to show the
/// user directly (already sanitized by the API — see
/// docs/05_SECURITY_MODEL.md's "no unnecessary security details" rule).
class AuthApiException implements Exception {
  AuthApiException(this.message, {this.statusCode});

  final String message;

  /// The HTTP status of an unsuccessful response, or null when no response
  /// arrived at all (a network failure). Lets `AuthController` tell a 401
  /// (token no longer valid) apart from an inconclusive failure.
  final int? statusCode;

  @override
  String toString() => message;
}

class LoginResult {
  const LoginResult({required this.user, required this.token});

  final AuthUser user;
  final String token;
}

/// Talks to `/api/v1/auth/*` (Phase 4). Bearer-token authentication only —
/// no cookie/session state — per DEC-022.
class AuthApiClient {
  AuthApiClient({http.Client? httpClient, String? baseUrl})
    : _httpClient = httpClient ?? http.Client(),
      _baseUrl = baseUrl ?? AppConfig.apiBaseUrl;

  final http.Client _httpClient;
  final String _baseUrl;

  static const _jsonHeaders = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };

  Future<LoginResult> login({
    required String email,
    required String password,
  }) async {
    final response = await _post(
      '/auth/login',
      body: {'email': email, 'password': password},
    );

    final data = _dataOf(response);

    return LoginResult(
      user: AuthUser.fromJson(data['user'] as Map<String, dynamic>),
      token: data['token'] as String,
    );
  }

  Future<AuthUser> me(String token) async {
    final response = await _get('/auth/me', token: token);

    return AuthUser.fromJson(_dataOf(response));
  }

  Future<void> logout(String token) async {
    await _post('/auth/logout', token: token);
  }

  Future<http.Response> _post(
    String path, {
    Map<String, dynamic>? body,
    String? token,
  }) {
    return _send(
      () => _httpClient.post(
        _uri(path),
        headers: _headersFor(token),
        body: body == null ? null : jsonEncode(body),
      ),
    );
  }

  Future<http.Response> _get(String path, {String? token}) {
    return _send(
      () => _httpClient.get(_uri(path), headers: _headersFor(token)),
    );
  }

  Future<http.Response> _send(Future<http.Response> Function() request) async {
    final http.Response response;

    try {
      response = await request();
    } on Exception {
      throw AuthApiException(
        'Could not reach the server. Check your connection and try again.',
      );
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return response;
    }

    throw AuthApiException(
      _extractMessage(response),
      statusCode: response.statusCode,
    );
  }

  Map<String, dynamic> _dataOf(http.Response response) {
    final decoded = jsonDecode(response.body) as Map<String, dynamic>;

    return decoded['data'] as Map<String, dynamic>;
  }

  Uri _uri(String path) => Uri.parse('$_baseUrl$path');

  Map<String, String> _headersFor(String? token) => {
    ..._jsonHeaders,
    if (token != null) 'Authorization': 'Bearer $token',
  };

  String _extractMessage(http.Response response) {
    try {
      final body = jsonDecode(response.body) as Map<String, dynamic>;

      final errors = body['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final firstError = errors.values.first;
        if (firstError is List && firstError.isNotEmpty) {
          return firstError.first.toString();
        }
      }

      final message = body['message'];
      if (message is String && message.isNotEmpty) {
        return message;
      }
    } on FormatException {
      // Fall through to the generic message below.
    }

    return 'Something went wrong. Please try again.';
  }
}

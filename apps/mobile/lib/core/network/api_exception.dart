/// The minimal failure model for authenticated API requests made through
/// [ApiClient] (Phase 27, docs/phases/V1_PHASE_27_DEFINITION.md §7). Each
/// case maps to a distinct UI reaction, nothing more: a screen shows
/// [message] with a retry for the recoverable cases, and does nothing for
/// [ApiSessionExpiredException] because the router has already returned to
/// Login. Messages are always safe to show — never a token or raw body.
sealed class ApiException implements Exception {
  const ApiException(this.message);

  final String message;

  @override
  String toString() => message;
}

/// The authenticated session has ended (a 401, or a 403 whose `/auth/me`
/// re-check returned 401). The local session has already been invalidated
/// by the time this is thrown.
final class ApiSessionExpiredException extends ApiException {
  const ApiSessionExpiredException()
    : super('Your session has ended. Please sign in again.');
}

/// A 403 that did not end the session: an ordinary refusal of this
/// particular request. [message] is the server's own sanitized message.
final class ApiForbiddenException extends ApiException {
  const ApiForbiddenException(super.message);
}

/// The request never produced an HTTP response (offline, DNS, TLS, reset).
final class ApiNetworkException extends ApiException {
  const ApiNetworkException()
    : super('Could not reach the server. Check your connection and try again.');
}

/// A 5xx response.
final class ApiServerException extends ApiException {
  const ApiServerException(this.statusCode)
    : super('Something went wrong. Please try again.');

  final int statusCode;
}

/// Any other unsuccessful response (e.g. 404, 422, 429) or a 2xx body that
/// isn't the expected JSON object.
final class ApiRequestException extends ApiException {
  const ApiRequestException(super.message, {this.statusCode});

  final int? statusCode;
}

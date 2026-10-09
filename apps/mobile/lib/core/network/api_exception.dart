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

/// Any other unsuccessful response (e.g. 404, 429) or a 2xx body that
/// isn't the expected JSON object. A 422 is the more specific
/// [ApiValidationException].
base class ApiRequestException extends ApiException {
  const ApiRequestException(super.message, {this.statusCode});

  final int? statusCode;
}

/// A 422 (Phase 29A, spec §5.2): the server rejected the submitted data.
/// [message] is the server's summary; [fieldErrors] maps each rejected
/// field to its messages (empty when the server sent none). Still an
/// [ApiRequestException], so code that treats every request failure alike
/// keeps working.
final class ApiValidationException extends ApiRequestException {
  const ApiValidationException(super.message, {this.fieldErrors = const {}})
    : super(statusCode: 422);

  final Map<String, List<String>> fieldErrors;

  /// The first message for [field], or null.
  String? firstErrorFor(String field) {
    final errors = fieldErrors[field];

    return errors == null || errors.isEmpty ? null : errors.first;
  }
}

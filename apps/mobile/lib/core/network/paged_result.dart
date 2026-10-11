/// One page of a Laravel length-aware paginator response: `data` plus
/// `meta.current_page`, `meta.last_page` and `meta.total` (Phase 29C,
/// docs/phases/V1_PHASE_29_DEFINITION.md §7.4).
class PagedResult<T> {
  const PagedResult({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  /// Parses the whole response body (not just `data`), building each item
  /// with [item]. A body that doesn't match the paginator shape throws a
  /// [FormatException].
  factory PagedResult.fromJson(
    Map<String, dynamic> json,
    T Function(Map<String, dynamic> json) item,
  ) {
    try {
      final meta = json['meta'] as Map<String, dynamic>;

      return PagedResult(
        items: (json['data'] as List<dynamic>)
            .map((e) => item(e as Map<String, dynamic>))
            .toList(growable: false),
        currentPage: meta['current_page'] as int,
        lastPage: meta['last_page'] as int,
        total: meta['total'] as int,
      );
    } on TypeError catch (e) {
      throw FormatException('Unexpected paginated response shape: $e');
    }
  }

  final List<T> items;
  final int currentPage;
  final int lastPage;

  /// Every matching record across all pages.
  final int total;

  bool get hasMore => currentPage < lastPage;
}

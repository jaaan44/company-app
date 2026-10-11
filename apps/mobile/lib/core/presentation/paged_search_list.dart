import 'package:flutter/material.dart';

import 'package:mobile/core/state/paged_search_controller.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';

/// The body of a searchable "load more" list screen — the Phase 28 Staff
/// directory's layout, shared by Projects and Clients (Phase 29C,
/// docs/phases/V1_PHASE_29_DEFINITION.md §7.5): a search field, then the
/// loading / error / no-profile / empty / list states, pull-to-refresh and
/// a "load more" footer that retries on tap after a failure.
///
/// The page owns [controller] and its `Scaffold`; this widget only renders.
/// Widget keys are prefixed with [keyPrefix] (`<prefix>-search`,
/// `<prefix>-list`, `<prefix>-empty`, `<prefix>-no-profile`,
/// `<prefix>-loading-more`, `<prefix>-load-more-retry`).
class PagedSearchList<T> extends StatefulWidget {
  const PagedSearchList({
    super.key,
    required this.controller,
    required this.keyPrefix,
    required this.searchHint,
    required this.rowBuilder,
    required this.emptyMessage,
    required this.searchEmptyMessage,
    required this.errorFallback,
    this.noProfileMessage = 'No staff profile is linked to this account.',
  });

  final PagedSearchController<T> controller;
  final String keyPrefix;
  final String searchHint;
  final Widget Function(BuildContext context, T item) rowBuilder;

  /// Shown when the list is empty without a search.
  final String emptyMessage;

  /// Shown when a search matches nothing; given the trimmed query.
  final String Function(String query) searchEmptyMessage;

  /// The error text when the controller gives none.
  final String errorFallback;
  final String noProfileMessage;

  @override
  State<PagedSearchList<T>> createState() => _PagedSearchListState<T>();
}

class _PagedSearchListState<T> extends State<PagedSearchList<T>> {
  final TextEditingController _search = TextEditingController();

  PagedSearchController<T> get _controller => widget.controller;
  String get _prefix => widget.keyPrefix;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _refresh() async {
    if (!await _controller.refresh() && mounted) {
      showRefreshFailed(context);
    }
  }

  void _clearSearch() {
    _search.clear();
    _controller.setQuery('');
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final padding = peopleListPadding(constraints);

        return ListenableBuilder(
          listenable: _controller,
          builder: (context, _) {
            // Searching means nothing without a profile.
            if (_controller.status == PagedListStatus.noProfile) {
              return _message(
                widget.noProfileMessage,
                Key('$_prefix-no-profile'),
              );
            }

            return Column(
              children: [
                Padding(
                  padding: EdgeInsets.fromLTRB(
                    padding.left,
                    8,
                    padding.right,
                    8,
                  ),
                  child: ListenableBuilder(
                    listenable: _search,
                    builder: (context, _) => TextField(
                      key: Key('$_prefix-search'),
                      controller: _search,
                      onChanged: _controller.setQuery,
                      textInputAction: TextInputAction.search,
                      decoration: InputDecoration(
                        hintText: widget.searchHint,
                        prefixIcon: const Icon(Icons.search),
                        border: const OutlineInputBorder(),
                        suffixIcon: _search.text.isEmpty
                            ? null
                            : IconButton(
                                icon: const Icon(Icons.clear),
                                tooltip: 'Clear search',
                                onPressed: _clearSearch,
                              ),
                      ),
                    ),
                  ),
                ),
                Expanded(child: _body(context, padding)),
              ],
            );
          },
        );
      },
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_controller.status) {
      case PagedListStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case PagedListStatus.error:
        return PeopleErrorView(
          message: _controller.errorMessage ?? widget.errorFallback,
          onRetry: _controller.load,
        );
      case PagedListStatus.loaded:
      case PagedListStatus.noProfile:
        break;
    }

    final items = _controller.items;

    if (items.isEmpty) {
      final query = _controller.query;

      return RefreshIndicator(
        onRefresh: _refresh,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(24),
          children: [
            Text(
              key: Key('$_prefix-empty'),
              query.isEmpty
                  ? widget.emptyMessage
                  : widget.searchEmptyMessage(query),
              textAlign: TextAlign.center,
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView.builder(
        key: Key('$_prefix-list'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: EdgeInsets.fromLTRB(padding.left, 0, padding.right, 16),
        itemCount: items.length + (_controller.hasMore ? 1 : 0),
        itemBuilder: (context, index) => index < items.length
            ? widget.rowBuilder(context, items[index])
            : _LoadMoreFooter(controller: _controller, keyPrefix: _prefix),
      ),
    );
  }

  Widget _message(String text, Key key) => RefreshIndicator(
    onRefresh: _refresh,
    child: ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(24),
      children: [Text(key: key, text, textAlign: TextAlign.center)],
    ),
  );
}

/// The last row while more pages remain. Being built means the user has
/// scrolled near the end, so it asks for the next page; after a failure it
/// becomes a "tap to retry" row instead of retrying on its own.
class _LoadMoreFooter extends StatelessWidget {
  const _LoadMoreFooter({required this.controller, required this.keyPrefix});

  final PagedSearchController<Object?> controller;
  final String keyPrefix;

  @override
  Widget build(BuildContext context) {
    if (controller.loadMoreFailed) {
      return ListTile(
        key: Key('$keyPrefix-load-more-retry'),
        leading: const Icon(Icons.refresh),
        title: const Text("Couldn't load more. Tap to retry."),
        onTap: controller.loadMore,
      );
    }

    if (!controller.isLoadingMore) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        controller.loadMore();
      });
    }

    return Padding(
      key: Key('$keyPrefix-loading-more'),
      padding: const EdgeInsets.all(16),
      child: const Center(child: CircularProgressIndicator()),
    );
  }
}

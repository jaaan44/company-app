import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/people/domain/staff_member.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/people/state/staff_directory_controller.dart';

/// The Staff directory (Phase 28, spec §8): active colleagues (R-1) in
/// last-name order, a name search, "load more" paging and pull-to-refresh.
/// Each row shows the display name and "position · department" (R-5) and
/// opens that person's entry.
class StaffDirectoryPage extends StatefulWidget {
  const StaffDirectoryPage({super.key, required this.peopleApiClient});

  final PeopleApiClient peopleApiClient;

  @override
  State<StaffDirectoryPage> createState() => _StaffDirectoryPageState();
}

class _StaffDirectoryPageState extends State<StaffDirectoryPage> {
  late final StaffDirectoryController _controller = StaffDirectoryController(
    widget.peopleApiClient,
  );
  final TextEditingController _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _controller.load();
  }

  @override
  void dispose() {
    _controller.dispose();
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
    return Scaffold(
      appBar: AppBar(title: const Text('Staff directory')),
      body: LayoutBuilder(
        builder: (context, constraints) {
          final padding = peopleListPadding(constraints);

          return Column(
            children: [
              Padding(
                padding: EdgeInsets.fromLTRB(padding.left, 8, padding.right, 8),
                child: ListenableBuilder(
                  listenable: _search,
                  builder: (context, _) => TextField(
                    key: const Key('directory-search'),
                    controller: _search,
                    onChanged: _controller.setQuery,
                    textInputAction: TextInputAction.search,
                    decoration: InputDecoration(
                      hintText: 'Search by name',
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
              Expanded(
                child: ListenableBuilder(
                  listenable: _controller,
                  builder: (context, _) => _body(context, padding),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_controller.status) {
      case DirectoryStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case DirectoryStatus.error:
        return PeopleErrorView(
          message:
              _controller.errorMessage ??
              'Something went wrong loading the staff directory.',
          onRetry: _controller.load,
        );
      case DirectoryStatus.loaded:
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
              key: const Key('directory-empty'),
              query.isEmpty
                  ? 'No active staff yet.'
                  : 'No one matches "$query".',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView.builder(
        key: const Key('directory-list'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: EdgeInsets.fromLTRB(padding.left, 0, padding.right, 16),
        itemCount: items.length + (_controller.hasMore ? 1 : 0),
        itemBuilder: (context, index) => index < items.length
            ? _StaffRow(member: items[index])
            : _LoadMoreFooter(controller: _controller),
      ),
    );
  }
}

class _StaffRow extends StatelessWidget {
  const _StaffRow({required this.member});

  final StaffMember member;

  @override
  Widget build(BuildContext context) {
    final roleLine = member.roleLine;

    return ListTile(
      key: Key('directory-row-${member.publicId}'),
      title: Text(member.displayName),
      subtitle: roleLine == null ? null : Text(roleLine),
      trailing: const Icon(Icons.chevron_right),
      onTap: () => context.push(
        '/more/directory/${Uri.encodeComponent(member.publicId)}',
      ),
    );
  }
}

/// The last row while more pages remain. Being built means the user has
/// scrolled near the end (the list builds lazily), so it asks for the next
/// page — which also covers a first page too short to scroll. After a
/// failure it becomes a "tap to retry" row instead of retrying on its own.
class _LoadMoreFooter extends StatelessWidget {
  const _LoadMoreFooter({required this.controller});

  final StaffDirectoryController controller;

  @override
  Widget build(BuildContext context) {
    if (controller.loadMoreFailed) {
      return ListTile(
        key: const Key('directory-load-more-retry'),
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

    return const Padding(
      key: Key('directory-loading-more'),
      padding: EdgeInsets.all(16),
      child: Center(child: CircularProgressIndicator()),
    );
  }
}

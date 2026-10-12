import 'package:flutter/material.dart';

import 'package:mobile/core/presentation/paged_search_list.dart';
import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';
import 'package:mobile/features/my_status/presentation/my_status_widgets.dart';
import 'package:mobile/features/my_status/state/history_list_controllers.dart';

/// All check-ins (Phase 30B, R-13): every check-in I made, newest first,
/// 20 a page, read-only. Never searched.
class CheckInsPage extends StatefulWidget {
  const CheckInsPage({super.key, required this.myStatusApiClient});

  final MyStatusApiClient myStatusApiClient;

  @override
  State<CheckInsPage> createState() => _CheckInsPageState();
}

class _CheckInsPageState extends State<CheckInsPage> {
  late final CheckInsController _controller = CheckInsController(
    widget.myStatusApiClient,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('All check-ins')),
      body: PagedSearchList<CheckIn>(
        controller: _controller,
        keyPrefix: 'check-ins',
        emptyMessage: "You haven't checked in yet.",
        errorFallback: 'Something went wrong loading your check-ins.',
        rowBuilder: (context, checkIn) => CheckInRow(
          key: Key('check-ins-row-${checkIn.publicId}'),
          checkIn: checkIn,
          companyDay: _controller.companyDay!,
        ),
      ),
    );
  }
}

/// All status changes (Phase 30B, R-13): newest first, 20 a page.
class StatusHistoryPage extends StatefulWidget {
  const StatusHistoryPage({super.key, required this.myStatusApiClient});

  final MyStatusApiClient myStatusApiClient;

  @override
  State<StatusHistoryPage> createState() => _StatusHistoryPageState();
}

class _StatusHistoryPageState extends State<StatusHistoryPage> {
  late final StatusHistoryController _controller = StatusHistoryController(
    widget.myStatusApiClient,
  );

  @override
  void initState() {
    super.initState();
    _controller.load();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('All status changes')),
      body: PagedSearchList<StatusEntry>(
        controller: _controller,
        keyPrefix: 'status-history',
        emptyMessage: "You haven't set a status yet.",
        errorFallback: 'Something went wrong loading your status changes.',
        rowBuilder: (context, entry) => StatusRow(
          key: Key('status-history-row-${entry.identity}'),
          entry: entry,
          companyDay: _controller.companyDay!,
        ),
      ),
    );
  }
}

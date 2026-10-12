import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/leave/presentation/leave_widgets.dart';
import 'package:mobile/features/leave/state/leave_changes.dart';
import 'package:mobile/features/leave/state/leave_request_controller.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';

/// One leave request (Phase 31): mine (R-6 — **Cancel request** while
/// pending, or approved and not yet started by the company day) or a
/// direct report's (R-7 — **Approve** with an optional note, **Reject**
/// with a reason, while pending). Every action is confirmed in a dialog,
/// sent once, and shown only when the server answers.
class LeaveRequestPage extends StatefulWidget {
  const LeaveRequestPage({
    super.key,
    required this.leaveApiClient,
    required this.leaveChanges,
    required this.publicId,
    required this.viewer,
    this.args,
  });

  final LeaveApiClient leaveApiClient;
  final LeaveChanges leaveChanges;
  final String publicId;
  final LeaveRequestViewer viewer;
  final LeaveRequestArgs? args;

  @override
  State<LeaveRequestPage> createState() => _LeaveRequestPageState();
}

class _LeaveRequestPageState extends State<LeaveRequestPage> {
  late final LeaveRequestController _controller = LeaveRequestController(
    widget.leaveApiClient,
    widget.publicId,
    viewer: widget.viewer,
    initial: widget.args?.initial,
    companyDay: widget.args?.companyDay,
    changes: widget.leaveChanges,
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

  Future<void> _refresh() async {
    if (!await _controller.refresh() && mounted) {
      showRefreshFailed(context);
    }
  }

  void _done(String message) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _cancel() async {
    final reason = await _askText(
      title: 'Cancel this request?',
      body: _controller.data?.status == LeaveStatus.approved
          ? 'Your approved leave will be cancelled.'
          : 'Your manager will no longer see it as pending.',
      label: 'Reason (optional)',
      confirm: 'Cancel request',
      dismiss: 'Keep it',
      confirmKey: 'leave-cancel-confirm',
      destructive: true,
    );
    if (reason == null || !mounted) {
      return;
    }
    if (await _controller.cancel(reason: reason) && mounted) {
      _done('Request cancelled.');
    }
  }

  Future<void> _approve() async {
    final note = await _askText(
      title: 'Approve this request?',
      body: null,
      label: 'Note (optional)',
      confirm: 'Approve',
      dismiss: 'Back',
      confirmKey: 'leave-approve-confirm',
    );
    if (note == null || !mounted) {
      return;
    }
    if (await _controller.approve(note: note) && mounted) {
      _done('Request approved.');
    }
  }

  Future<void> _reject() async {
    final reason = await _askText(
      title: 'Reject this request?',
      body: null,
      label: 'Reason',
      confirm: 'Reject',
      dismiss: 'Back',
      confirmKey: 'leave-reject-confirm',
      required: true,
      destructive: true,
    );
    if (reason == null || !mounted) {
      return;
    }
    if (await _controller.reject(reason: reason) && mounted) {
      _done('Request rejected.');
    }
  }

  /// A confirmation with one text field. Returns the text ('' when left
  /// empty), or null when dismissed. A [required] field needs 3–1000
  /// characters before the confirm button works.
  Future<String?> _askText({
    required String title,
    required String? body,
    required String label,
    required String confirm,
    required String dismiss,
    required String confirmKey,
    bool required = false,
    bool destructive = false,
  }) => showDialog<String>(
    context: context,
    builder: (context) => _TextConfirmDialog(
      title: title,
      body: body,
      label: label,
      confirm: confirm,
      dismiss: dismiss,
      confirmKey: confirmKey,
      required: required,
      destructive: destructive,
    ),
  );

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _controller,
      builder: (context, _) => PopScope(
        // Leaving wouldn't stop an action already sent (30B Gate 4).
        canPop: !_controller.isActing,
        child: Scaffold(
          appBar: AppBar(
            title: Text(
              widget.viewer == LeaveRequestViewer.team
                  ? 'Team request'
                  : 'Leave request',
            ),
          ),
          body: LayoutBuilder(
            builder: (context, constraints) =>
                _body(context, peopleListPadding(constraints)),
          ),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    final request = _controller.data;
    switch (_controller.status) {
      case LeaveRequestStatusView.loading:
        return const Center(child: CircularProgressIndicator());
      case LeaveRequestStatusView.error:
        return PeopleErrorView(
          message:
              _controller.errorMessage ??
              'Something went wrong loading this request.',
          onRetry: _controller.load,
        );
      case LeaveRequestStatusView.loaded:
        break;
    }
    if (request == null) {
      return const SizedBox.shrink();
    }

    final theme = Theme.of(context);
    final busy = _controller.isActing;
    final message = _controller.actionMessage;
    final day = _controller.companyDay;

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        key: const Key('leave-detail'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: padding,
        children: [
          if (message != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
              child: LeaveErrorBanner(
                message,
                key: const Key('leave-action-error'),
              ),
            ),
          ListTile(
            title: Text(
              request.leaveType.name,
              style: theme.textTheme.titleLarge,
            ),
            subtitle: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(request.leaveType.isPaid ? 'Paid' : 'Unpaid'),
                const SizedBox(height: 4),
                LeaveStatusChip(request.status),
              ],
            ),
          ),
          if (widget.viewer == LeaveRequestViewer.team)
            PeopleInfoRow(
              icon: Icons.person_outline,
              label: 'Requested by',
              value: request.staff?.displayName,
            ),
          PeopleInfoRow(
            icon: Icons.event_outlined,
            label: calendarDaysLabel(request.totalDays),
            value: leaveDatesLabel(request.startDate, request.endDate),
          ),
          PeopleInfoRow(
            icon: Icons.notes_outlined,
            label: 'Reason',
            value: request.reason,
          ),
          if (_controller.canCancel || _controller.canDecide)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
              child: _actions(theme, busy),
            ),
          const PeopleSectionHeader('History'),
          if (request.history.isEmpty)
            const ListTile(title: Text('No history yet.')),
          for (final (i, h) in request.history.indexed)
            ListTile(
              key: Key('leave-history-$i'),
              leading: Icon(_historyIcon(h.action)),
              title: Text(historyLabel(h, day)),
              subtitle: h.note == null ? null : Text(h.note!),
            ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }

  Widget _actions(ThemeData theme, bool busy) {
    Widget progressOr(String label) => busy
        ? const SizedBox(
            height: 20,
            width: 20,
            child: CircularProgressIndicator(strokeWidth: 2),
          )
        : Text(label);

    if (_controller.canCancel) {
      return OutlinedButton(
        key: const Key('leave-cancel'),
        onPressed: busy ? null : _cancel,
        style: OutlinedButton.styleFrom(
          foregroundColor: theme.colorScheme.error,
        ),
        child: progressOr('Cancel request'),
      );
    }

    return Row(
      children: [
        Expanded(
          child: OutlinedButton(
            key: const Key('leave-reject'),
            onPressed: busy ? null : _reject,
            style: OutlinedButton.styleFrom(
              foregroundColor: theme.colorScheme.error,
            ),
            child: progressOr('Reject'),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: FilledButton(
            key: const Key('leave-approve'),
            onPressed: busy ? null : _approve,
            child: progressOr('Approve'),
          ),
        ),
      ],
    );
  }

  IconData _historyIcon(LeaveAction a) => switch (a) {
    LeaveAction.submitted => Icons.send_outlined,
    LeaveAction.approved => Icons.check_circle_outline,
    LeaveAction.rejected => Icons.block,
    LeaveAction.cancelled => Icons.cancel_outlined,
    LeaveAction.unknown => Icons.history,
  };
}

class _TextConfirmDialog extends StatefulWidget {
  const _TextConfirmDialog({
    required this.title,
    required this.body,
    required this.label,
    required this.confirm,
    required this.dismiss,
    required this.confirmKey,
    required this.required,
    required this.destructive,
  });

  final String title;
  final String? body;
  final String label;
  final String confirm;
  final String dismiss;
  final String confirmKey;
  final bool required;
  final bool destructive;

  @override
  State<_TextConfirmDialog> createState() => _TextConfirmDialogState();
}

class _TextConfirmDialogState extends State<_TextConfirmDialog> {
  final TextEditingController _text = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  void _confirm() {
    final value = _text.text.trim();
    if (widget.required && value.length < LeaveLimits.minReasonLength) {
      setState(
        () => _error =
            'Give a reason (at least ${LeaveLimits.minReasonLength} characters).',
      );

      return;
    }
    if (value.length > LeaveLimits.maxNoteLength) {
      setState(
        () => _error = 'Use at most ${LeaveLimits.maxNoteLength} characters.',
      );

      return;
    }
    Navigator.of(context).pop(value);
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return AlertDialog(
      title: Text(widget.title),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (widget.body != null) ...[
              Text(widget.body!),
              const SizedBox(height: 12),
            ],
            TextField(
              key: const Key('leave-dialog-text'),
              controller: _text,
              minLines: 2,
              maxLines: 4,
              maxLength: LeaveLimits.maxNoteLength,
              maxLengthEnforcement: MaxLengthEnforcement.none,
              decoration: InputDecoration(
                labelText: widget.label,
                border: const OutlineInputBorder(),
                errorText: _error,
                errorMaxLines: 3,
              ),
              onChanged: (_) {
                if (_error != null) {
                  setState(() => _error = null);
                }
              },
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(widget.dismiss),
        ),
        TextButton(
          key: Key(widget.confirmKey),
          onPressed: _confirm,
          style: widget.destructive
              ? TextButton.styleFrom(foregroundColor: scheme.error)
              : null,
          child: Text(widget.confirm),
        ),
      ],
    );
  }
}

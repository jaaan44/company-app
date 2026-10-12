import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/domain/leave.dart';
import 'package:mobile/features/leave/presentation/leave_widgets.dart';
import 'package:mobile/features/leave/state/leave_changes.dart';
import 'package:mobile/features/leave/state/leave_form_controller.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/work_logs/presentation/work_log_widgets.dart'
    show apiDateOf, pickerDateOf, workLogDateLabel;

/// Request leave (Phase 31, R-5): type (paid ones with this year's days
/// left), first and last day, the computed "N calendar days (weekends
/// included)", and a reason. Checked before sending, sent once, the
/// server's refusals shown in its words; "Discard this request?" when
/// leaving with something entered. See [LeaveFormController].
class LeaveFormPage extends StatefulWidget {
  const LeaveFormPage({
    super.key,
    required this.leaveApiClient,
    required this.leaveChanges,
    this.args,
  });

  final LeaveApiClient leaveApiClient;
  final LeaveChanges leaveChanges;
  final LeaveFormArgs? args;

  @override
  State<LeaveFormPage> createState() => _LeaveFormPageState();
}

class _LeaveFormPageState extends State<LeaveFormPage> {
  late final LeaveFormController _controller = LeaveFormController(
    widget.leaveApiClient,
    balances: widget.args?.balances,
    companyDate: widget.args?.companyDate,
    changes: widget.leaveChanges,
  );
  final TextEditingController _reason = TextEditingController();
  bool _leaving = false;

  @override
  void initState() {
    super.initState();
    _controller.load();
  }

  @override
  void dispose() {
    _controller.dispose();
    _reason.dispose();
    super.dispose();
  }

  void _leave() {
    setState(() => _leaving = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        context.pop();
      }
    });
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    if (await _controller.submit() && mounted) {
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(const SnackBar(content: Text('Leave requested.')));
      _leave();
    }
  }

  Future<void> _confirmDiscard() async {
    final discard = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Discard this request?'),
        content: const Text("What you've entered won't be sent."),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep editing'),
          ),
          TextButton(
            key: const Key('leave-discard-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Discard'),
          ),
        ],
      ),
    );
    if (discard == true && mounted) {
      _leave();
    }
  }

  /// The pickers reach from 1 January last year to 31 December next year
  /// of the company year — the API takes any dates, one year at a time.
  Future<void> _pickDate({required bool start}) async {
    final today = _controller.companyDate;
    if (today == null) {
      return;
    }
    final year = int.parse(today.substring(0, 4));
    final current =
        (start ? _controller.startDate : _controller.endDate) ?? today;
    final picked = await showDatePicker(
      context: context,
      initialDate: pickerDateOf(current),
      firstDate: DateTime(year - 1),
      lastDate: DateTime(year + 1, 12, 31),
      helpText: start ? 'First day of leave' : 'Last day of leave',
    );
    if (picked == null) {
      return;
    }
    final value = apiDateOf(picked);
    if (start) {
      _controller.setStartDate(value);
    } else {
      _controller.setEndDate(value);
    }
  }

  Future<void> _pickType() async {
    final chosen = await showModalBottomSheet<LeaveBalance>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => SafeArea(
        child: ListView(
          key: const Key('leave-type-picker'),
          shrinkWrap: true,
          children: [
            for (final t in _controller.types)
              ListTile(
                key: Key('leave-type-${t.leaveType.publicId}'),
                title: Text(t.leaveType.name),
                subtitle: Text(
                  t.isPaid
                      ? '${daysLabel(t.remainingDays ?? 0)} left in ${t.year}'
                      : 'Unpaid',
                ),
                selected:
                    _controller.type?.leaveType.publicId ==
                    t.leaveType.publicId,
                trailing:
                    _controller.type?.leaveType.publicId == t.leaveType.publicId
                    ? const Icon(Icons.check)
                    : null,
                onTap: () => Navigator.of(context).pop(t),
              ),
            const SizedBox(height: 16),
          ],
        ),
      ),
    );
    if (chosen != null) {
      _controller.setType(chosen);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _controller,
      builder: (context, _) => PopScope(
        canPop: _leaving || (!_controller.isSubmitting && !_controller.isDirty),
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop && !_controller.isSubmitting) {
            _confirmDiscard();
          }
        },
        child: Scaffold(
          appBar: AppBar(title: const Text('Request leave')),
          body: LayoutBuilder(
            builder: (context, constraints) =>
                _body(context, peopleListPadding(constraints)),
          ),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_controller.status) {
      case LeaveFormStatus.loading:
        return const Center(child: CircularProgressIndicator());
      case LeaveFormStatus.error:
        return PeopleErrorView(
          message:
              _controller.loadError ?? 'Something went wrong loading the form.',
          onRetry: _controller.load,
        );
      case LeaveFormStatus.noProfile:
        return const LeaveNoProfile();
      case LeaveFormStatus.ready:
        break;
    }

    final theme = Theme.of(context);
    final errors = _controller.fieldErrors;
    final general = _controller.generalError;
    final busy = _controller.isSubmitting;
    final start = _controller.startDate;
    final end = _controller.endDate;
    final days = _controller.days;
    final hint = _controller.balanceHint;

    return ListView(
      key: const Key('leave-form'),
      padding: EdgeInsets.fromLTRB(
        padding.left + 16,
        16,
        padding.right + 16,
        32,
      ),
      children: [
        if (general != null) ...[
          LeaveErrorBanner(general, key: const Key('leave-form-error')),
          const SizedBox(height: 16),
        ],
        _PickerField(
          key: const Key('leave-form-type'),
          label: 'Type of leave',
          value: _controller.type?.leaveType.name,
          detail:
              hint ??
              (_controller.type?.isPaid == false ? 'Unpaid leave' : null),
          placeholder: 'Choose a type',
          icon: Icons.category_outlined,
          error: errors[LeaveField.type],
          onTap: busy ? null : _pickType,
        ),
        const SizedBox(height: 12),
        _PickerField(
          key: const Key('leave-form-start'),
          label: 'First day',
          value: start == null ? null : workLogDateLabel(start),
          detail: start == _controller.companyDate ? 'Today' : null,
          placeholder: 'Choose a date',
          icon: Icons.event_outlined,
          error: errors[LeaveField.start],
          onTap: busy ? null : () => _pickDate(start: true),
        ),
        const SizedBox(height: 12),
        _PickerField(
          key: const Key('leave-form-end'),
          label: 'Last day',
          value: end == null ? null : workLogDateLabel(end),
          detail: end == _controller.companyDate ? 'Today' : null,
          placeholder: 'Choose a date',
          icon: Icons.event_outlined,
          error: errors[LeaveField.end],
          onTap: busy ? null : () => _pickDate(start: false),
        ),
        if (days != null) ...[
          const SizedBox(height: 8),
          Text(
            calendarDaysLabel(days),
            key: const Key('leave-form-days'),
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
        const SizedBox(height: 16),
        TextFormField(
          key: const Key('leave-form-reason'),
          controller: _reason,
          enabled: !busy,
          minLines: 3,
          maxLines: 6,
          maxLength: LeaveLimits.maxReasonLength,
          maxLengthEnforcement: MaxLengthEnforcement.none,
          keyboardType: TextInputType.multiline,
          decoration: InputDecoration(
            labelText: 'Reason',
            alignLabelWithHint: true,
            border: const OutlineInputBorder(),
            errorText: errors[LeaveField.reason],
            errorMaxLines: 3,
          ),
          onChanged: _controller.setReason,
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('leave-form-submit'),
          onPressed: busy ? null : _submit,
          child: busy
              ? const SizedBox(
                  height: 20,
                  width: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Text('Send request'),
        ),
        const SizedBox(height: 8),
        Text(
          'Your manager approves or rejects it. You can cancel it while '
          "it's pending, or approved and not yet started.",
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ],
    );
  }
}

/// A labelled, tappable field showing a chosen value (or a placeholder),
/// with its error beneath.
class _PickerField extends StatelessWidget {
  const _PickerField({
    super.key,
    required this.label,
    required this.value,
    required this.placeholder,
    required this.icon,
    this.detail,
    this.error,
    this.onTap,
  });

  final String label;
  final String? value;
  final String? detail;
  final String placeholder;
  final IconData icon;
  final String? error;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          button: onTap != null,
          label: label,
          value: [value ?? placeholder, ?detail].join(', '),
          excludeSemantics: true,
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(4),
            child: InputDecorator(
              decoration: InputDecoration(
                labelText: label,
                border: const OutlineInputBorder(),
                enabledBorder: OutlineInputBorder(
                  borderSide: BorderSide(
                    color: error == null
                        ? theme.colorScheme.outline
                        : theme.colorScheme.error,
                  ),
                ),
                prefixIcon: Icon(icon),
                suffixIcon: onTap == null
                    ? null
                    : const Icon(Icons.arrow_drop_down),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    value ?? placeholder,
                    style: value == null
                        ? theme.textTheme.bodyLarge?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          )
                        : theme.textTheme.bodyLarge,
                  ),
                  if (detail != null)
                    Text(
                      detail!,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
        if (error case final message?) ...[
          const SizedBox(height: 6),
          Semantics(
            liveRegion: true,
            child: Padding(
              padding: const EdgeInsets.only(left: 12),
              child: Text(
                message,
                key: Key('${(key! as ValueKey<String>).value}-error'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }
}

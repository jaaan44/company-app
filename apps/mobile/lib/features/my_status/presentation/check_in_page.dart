import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/domain/my_status.dart';
import 'package:mobile/features/my_status/location/location_service.dart';
import 'package:mobile/features/my_status/presentation/my_status_widgets.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/my_status/state/check_in_flow_controller.dart';

/// The R-19 explanation, shown before Android's permission prompt.
const checkInExplanation =
    'Company App reads your location once, only when you tap Check in. '
    'It never tracks you in the background. Your manager and administrators '
    "can see your check-ins. A check-in isn't a clock-in.";

/// One explicit, foreground check-in (Phase 30B,
/// docs/phases/V1_PHASE_30_DEFINITION.md §6.5, R-19/R-20): the explanation
/// (unless location is already allowed), one position, an optional place
/// and note, **Send check-in**. Pops with the confirmed [CheckIn]; Cancel
/// or Back pops with nothing and sends nothing.
class CheckInPage extends StatefulWidget {
  const CheckInPage({
    super.key,
    required this.myStatusApiClient,
    required this.locationService,
    this.clock,
  });

  final MyStatusApiClient myStatusApiClient;
  final LocationService locationService;

  /// The device clock, for tests (only measures how old the fix is).
  final DateTime Function()? clock;

  @override
  State<CheckInPage> createState() => _CheckInPageState();
}

class _CheckInPageState extends State<CheckInPage> {
  late final CheckInFlowController _flow = CheckInFlowController(
    widget.myStatusApiClient,
    widget.locationService,
    clock: widget.clock,
  );
  final _place = TextEditingController();
  final _note = TextEditingController();

  @override
  void initState() {
    super.initState();
    _flow.start();
  }

  @override
  void dispose() {
    _flow.dispose();
    _place.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    FocusScope.of(context).unfocus();
    if (await _flow.send(locationLabel: _place.text, note: _note.text) &&
        mounted) {
      context.pop(_flow.sent);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Check in')),
      body: ListenableBuilder(
        listenable: _flow,
        builder: (context, _) => LayoutBuilder(
          builder: (context, constraints) =>
              _body(context, peopleListPadding(constraints)),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, EdgeInsets padding) {
    switch (_flow.step) {
      case CheckInStep.checking:
      case CheckInStep.locating:
      case CheckInStep.sent:
        return const _Locating();
      case CheckInStep.explaining:
        return _Explanation(
          padding: padding,
          onContinue: _flow.continueAfterExplanation,
          onCancel: () => context.pop(),
        );
      case CheckInStep.ready:
      case CheckInStep.sending:
        return _Form(
          padding: padding,
          fix: _flow.fix!,
          place: _place,
          note: _note,
          sending: _flow.step == CheckInStep.sending,
          sendError: _flow.sendError,
          onSend: _send,
          onCancel: () => context.pop(),
        );
      case CheckInStep.serviceOff:
      case CheckInStep.denied:
      case CheckInStep.deniedForever:
      case CheckInStep.timedOut:
      case CheckInStep.noProfile:
        return _Problem(
          padding: padding,
          step: _flow.step,
          message: _flow.problem!,
          onRetry: _flow.start,
          onOpenAppSettings: _flow.openAppSettings,
          onOpenLocationSettings: _flow.openLocationSettings,
        );
    }
  }
}

class _Locating extends StatelessWidget {
  const _Locating();

  @override
  Widget build(BuildContext context) {
    return const Center(
      key: Key('check-in-locating'),
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 16),
            Text('Finding your location…', textAlign: TextAlign.center),
          ],
        ),
      ),
    );
  }
}

class _Explanation extends StatelessWidget {
  const _Explanation({
    required this.padding,
    required this.onContinue,
    required this.onCancel,
  });

  final EdgeInsets padding;
  final VoidCallback onContinue;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: padding.add(const EdgeInsets.all(16)),
      children: [
        Icon(Icons.place_outlined, size: 48, color: theme.colorScheme.primary),
        const SizedBox(height: 16),
        Semantics(
          header: true,
          child: Text(
            'Before you check in',
            style: theme.textTheme.headlineSmall,
            textAlign: TextAlign.center,
          ),
        ),
        const SizedBox(height: 16),
        const Text(checkInExplanation, key: Key('check-in-explanation')),
        const SizedBox(height: 24),
        FilledButton(
          key: const Key('check-in-continue'),
          onPressed: onContinue,
          child: const Text('Continue'),
        ),
        const SizedBox(height: 8),
        TextButton(
          key: const Key('check-in-cancel'),
          onPressed: onCancel,
          child: const Text('Not now'),
        ),
      ],
    );
  }
}

class _Form extends StatelessWidget {
  const _Form({
    required this.padding,
    required this.fix,
    required this.place,
    required this.note,
    required this.sending,
    required this.sendError,
    required this.onSend,
    required this.onCancel,
  });

  final EdgeInsets padding;
  final LocationFix fix;
  final TextEditingController place;
  final TextEditingController note;
  final bool sending;
  final String? sendError;
  final VoidCallback onSend;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final error = sendError;

    return ListView(
      padding: padding.add(const EdgeInsets.all(16)),
      children: [
        Row(
          children: [
            Icon(
              fix.isApproximate ? Icons.location_searching : Icons.my_location,
              color: theme.colorScheme.primary,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                key: const Key('check-in-fix'),
                '${fix.isApproximate ? 'Approximate location' : 'Location found'}'
                ' (${accuracyLabel(fix.accuracyMeters)})',
                style: theme.textTheme.titleMedium,
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          coordinatesLabel(fix.latitude, fix.longitude),
          key: const Key('check-in-coordinates'),
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 16),
        TextField(
          key: const Key('check-in-place'),
          controller: place,
          enabled: !sending,
          maxLength: CheckInLimits.maxLabelLength,
          textInputAction: TextInputAction.next,
          decoration: const InputDecoration(
            labelText: 'Place (optional)',
            hintText: 'For example: Client site',
            border: OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          key: const Key('check-in-note'),
          controller: note,
          enabled: !sending,
          maxLength: CheckInLimits.maxNoteLength,
          minLines: 2,
          maxLines: 5,
          decoration: const InputDecoration(
            labelText: 'Note (optional)',
            border: OutlineInputBorder(),
          ),
        ),
        if (error != null) ...[
          const SizedBox(height: 8),
          Semantics(
            liveRegion: true,
            child: Text(
              error,
              key: const Key('check-in-send-error'),
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        ],
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('check-in-send'),
          onPressed: sending ? null : onSend,
          child: sending
              ? const SizedBox.square(
                  dimension: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Text('Send check-in'),
        ),
        const SizedBox(height: 8),
        TextButton(
          key: const Key('check-in-cancel'),
          onPressed: sending ? null : onCancel,
          child: const Text('Cancel'),
        ),
      ],
    );
  }
}

class _Problem extends StatelessWidget {
  const _Problem({
    required this.padding,
    required this.step,
    required this.message,
    required this.onRetry,
    required this.onOpenAppSettings,
    required this.onOpenLocationSettings,
  });

  final EdgeInsets padding;
  final CheckInStep step;
  final String message;
  final VoidCallback onRetry;
  final VoidCallback onOpenAppSettings;
  final VoidCallback onOpenLocationSettings;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: padding.add(const EdgeInsets.all(24)),
      children: [
        Icon(
          Icons.location_off_outlined,
          size: 48,
          color: theme.colorScheme.error,
        ),
        const SizedBox(height: 16),
        Semantics(
          liveRegion: true,
          child: Text(
            message,
            key: const Key('check-in-problem'),
            textAlign: TextAlign.center,
            style: theme.textTheme.titleMedium,
          ),
        ),
        const SizedBox(height: 24),
        if (step == CheckInStep.serviceOff)
          FilledButton(
            key: const Key('check-in-open-location-settings'),
            onPressed: onOpenLocationSettings,
            child: const Text('Open location settings'),
          ),
        if (step == CheckInStep.deniedForever)
          FilledButton(
            key: const Key('check-in-open-app-settings'),
            onPressed: onOpenAppSettings,
            child: const Text('Open app settings'),
          ),
        if (step != CheckInStep.noProfile) ...[
          const SizedBox(height: 8),
          OutlinedButton(
            key: const Key('check-in-retry'),
            onPressed: onRetry,
            child: const Text('Try again'),
          ),
        ],
      ],
    );
  }
}

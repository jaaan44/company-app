import 'package:flutter/material.dart';

/// Honest "not built yet" scaffolding for a bottom-navigation destination
/// with no business-module screen yet (Phase 25 scope — see
/// docs/phases/V1_PHASE_25_DEFINITION.md item 4). Each owning phase
/// (27–33) replaces its own destination's page with real content; nothing
/// here fetches data or contains business logic.
class PlaceholderPage extends StatelessWidget {
  const PlaceholderPage({
    super.key,
    required this.title,
    required this.message,
  });

  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: Center(
        child: Text(message, style: Theme.of(context).textTheme.bodyMedium),
      ),
    );
  }
}

class TasksPlaceholderPage extends StatelessWidget {
  const TasksPlaceholderPage({super.key});

  @override
  Widget build(BuildContext context) {
    return const PlaceholderPage(
      title: 'Tasks',
      message: 'Tasks — coming soon',
    );
  }
}

class SchedulePlaceholderPage extends StatelessWidget {
  const SchedulePlaceholderPage({super.key});

  @override
  Widget build(BuildContext context) {
    return const PlaceholderPage(
      title: 'Schedule',
      message: 'Schedule — coming soon',
    );
  }
}

class MessagesPlaceholderPage extends StatelessWidget {
  const MessagesPlaceholderPage({super.key});

  @override
  Widget build(BuildContext context) {
    return const PlaceholderPage(
      title: 'Messages',
      message: 'Messages — coming soon',
    );
  }
}

class MorePlaceholderPage extends StatelessWidget {
  const MorePlaceholderPage({super.key});

  @override
  Widget build(BuildContext context) {
    return const PlaceholderPage(title: 'More', message: 'More — coming soon');
  }
}

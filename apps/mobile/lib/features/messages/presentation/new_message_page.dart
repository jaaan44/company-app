import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/messages/data/messages_api_client.dart';
import 'package:mobile/features/messages/presentation/messages_widgets.dart';
import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/people/domain/staff_member.dart';
import 'package:mobile/features/people/presentation/people_widgets.dart';
import 'package:mobile/features/people/state/staff_directory_controller.dart';

/// New message (Phase 32, R-3): search the directory, pick a colleague
/// (never myself), and open our direct chat — reused if it exists.
class NewMessagePage extends StatefulWidget {
  const NewMessagePage({
    super.key,
    required this.messagesApiClient,
    required this.peopleApiClient,
    this.myStaffId,
  });

  final MessagesApiClient messagesApiClient;
  final PeopleApiClient peopleApiClient;
  final String? myStaffId;

  @override
  State<NewMessagePage> createState() => _NewMessagePageState();
}

class _NewMessagePageState extends State<NewMessagePage> {
  late final StaffDirectoryController _directory = StaffDirectoryController(
    widget.peopleApiClient,
  );
  final TextEditingController _search = TextEditingController();
  String? _myStaffId;
  String? _opening;
  String? _error;

  @override
  void initState() {
    super.initState();
    _myStaffId = widget.myStaffId;
    _directory.load();
    if (_myStaffId == null) {
      widget.messagesApiClient.fetchMyStaffPublicId().then((id) {
        if (mounted) {
          setState(() => _myStaffId = id);
        }
      }, onError: (Object _) {});
    }
  }

  @override
  void dispose() {
    _directory.dispose();
    _search.dispose();
    super.dispose();
  }

  Future<void> _open(StaffMember member) async {
    if (_opening != null) {
      return;
    }
    setState(() {
      _opening = member.publicId;
      _error = null;
    });
    try {
      final chat = await widget.messagesApiClient.openDirect(member.publicId);
      if (mounted) {
        context.pushReplacement(
          chatPath(chat.publicId),
          extra: ChatArgs(initial: chat, myStaffId: _myStaffId),
        );
      }
    } on ApiSessionExpiredException {
      // The app is returning to Login.
    } on ApiException catch (e) {
      if (mounted) {
        setState(
          () => _error = switch (e) {
            ApiNetworkException() =>
              "Couldn't open the conversation. Check your connection.",
            ApiValidationException(:final message) => message,
            ApiForbiddenException(:final message) => message,
            _ => "Couldn't open the conversation. Please try again.",
          },
        );
      }
    } finally {
      if (mounted) {
        setState(() => _opening = null);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New message')),
      body: LayoutBuilder(
        builder: (context, constraints) {
          final padding = peopleListPadding(constraints);

          return ListenableBuilder(
            listenable: _directory,
            builder: (context, _) {
              final people = _directory.items
                  .where((m) => m.publicId != _myStaffId)
                  .toList();

              return ListView(
                key: const Key('new-message-list'),
                padding: padding,
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                    child: TextField(
                      key: const Key('new-message-search'),
                      controller: _search,
                      decoration: const InputDecoration(
                        prefixIcon: Icon(Icons.search),
                        hintText: 'Search colleagues',
                        border: OutlineInputBorder(),
                      ),
                      onChanged: _directory.setQuery,
                    ),
                  ),
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: Text(
                        _error!,
                        key: const Key('new-message-error'),
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  if (_directory.status == DirectoryStatus.loading)
                    const Padding(
                      padding: EdgeInsets.all(24),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (_directory.status == DirectoryStatus.error)
                    PeopleErrorView(
                      message:
                          _directory.errorMessage ??
                          'Something went wrong loading the directory.',
                      onRetry: _directory.load,
                    )
                  else if (people.isEmpty)
                    const ListTile(title: Text('No colleagues found.'))
                  else
                    for (final m in people)
                      ListTile(
                        key: Key('new-message-person-${m.publicId}'),
                        leading: const CircleAvatar(
                          child: Icon(Icons.person_outline),
                        ),
                        title: Text(m.displayName),
                        subtitle: m.position == null
                            ? null
                            : Text(m.position!.name),
                        trailing: _opening == m.publicId
                            ? const SizedBox.square(
                                dimension: 20,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : null,
                        onTap: _opening == null ? () => _open(m) : null,
                      ),
                  if (_directory.hasMore)
                    TextButton(
                      key: const Key('new-message-more'),
                      onPressed: _directory.loadMore,
                      child: const Text('Show more'),
                    ),
                ],
              );
            },
          );
        },
      ),
    );
  }
}

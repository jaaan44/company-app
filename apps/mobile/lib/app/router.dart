import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/features/auth/presentation/login_page.dart';
import 'package:mobile/features/clients/data/clients_api_client.dart';
import 'package:mobile/features/clients/presentation/client_detail_page.dart';
import 'package:mobile/features/clients/presentation/clients_page.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/data/home_api_client.dart';
import 'package:mobile/features/announcements/data/announcements_api_client.dart';
import 'package:mobile/features/announcements/presentation/announcements_pages.dart';
import 'package:mobile/features/home/presentation/home_page.dart';
import 'package:mobile/features/messages/data/messages_api_client.dart';
import 'package:mobile/features/messages/presentation/chat_page.dart';
import 'package:mobile/features/messages/presentation/conversations_page.dart';
import 'package:mobile/features/messages/presentation/messages_widgets.dart'
    show ChatArgs;
import 'package:mobile/features/messages/presentation/new_message_page.dart';
import 'package:mobile/features/notifications/data/notifications_api_client.dart';
import 'package:mobile/features/notifications/presentation/notifications_page.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/presentation/leave_form_page.dart';
import 'package:mobile/features/leave/presentation/leave_page.dart';
import 'package:mobile/features/leave/presentation/leave_request_page.dart';
import 'package:mobile/features/leave/presentation/leave_widgets.dart';
import 'package:mobile/features/leave/presentation/team_leave_page.dart';
import 'package:mobile/features/leave/state/leave_changes.dart';
import 'package:mobile/features/leave/state/leave_request_controller.dart'
    show LeaveRequestViewer;
import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/people/presentation/more_page.dart';
import 'package:mobile/features/people/presentation/my_profile_page.dart';
import 'package:mobile/features/people/presentation/staff_detail_page.dart';
import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/location/location_service.dart';
import 'package:mobile/features/my_status/presentation/check_in_page.dart';
import 'package:mobile/features/my_status/presentation/history_pages.dart';
import 'package:mobile/features/my_status/presentation/my_status_page.dart';
import 'package:mobile/features/people/presentation/staff_directory_page.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/projects/presentation/project_detail_page.dart';
import 'package:mobile/features/projects/presentation/projects_page.dart';
import 'package:mobile/features/schedule/data/schedule_api_client.dart';
import 'package:mobile/features/schedule/presentation/schedule_entry_page.dart';
import 'package:mobile/features/schedule/presentation/schedule_page.dart';
import 'package:mobile/features/schedule/presentation/schedule_widgets.dart';
import 'package:mobile/features/shell/presentation/app_shell.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/presentation/task_detail_page.dart';
import 'package:mobile/features/tasks/presentation/task_widgets.dart';
import 'package:mobile/features/tasks/presentation/tasks_page.dart';
import 'package:mobile/features/tasks/state/task_changes.dart';
import 'package:mobile/features/work_logs/data/work_logs_api_client.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart' show TaskTarget;
import 'package:mobile/features/work_logs/presentation/my_work_logs_page.dart';
import 'package:mobile/features/work_logs/presentation/work_log_form_page.dart';
import 'package:mobile/features/work_logs/presentation/work_log_widgets.dart';
import 'package:mobile/features/work_logs/state/work_log_changes.dart';

/// Builds the app's single [GoRouter] — the sole navigation mechanism
/// (Phase 25, DEC-046), replacing the former [AuthGate] widget-switch with
/// declarative, route-level auth redirects. [authController] is also
/// wired in as `refreshListenable` so every status change (bootstrap
/// resolving, login, logout) re-evaluates [_redirect] automatically.
GoRouter buildAppRouter(
  AuthController authController, {
  required HomeApiClient homeApiClient,
  required PeopleApiClient peopleApiClient,
  required TasksApiClient tasksApiClient,
  required TaskChanges taskChanges,
  required WorkLogsApiClient workLogsApiClient,
  required WorkLogChanges workLogChanges,
  required ProjectsApiClient projectsApiClient,
  required ClientsApiClient clientsApiClient,
  required ScheduleApiClient scheduleApiClient,
  required MyStatusApiClient myStatusApiClient,
  required LocationService locationService,
  required LeaveApiClient leaveApiClient,
  required LeaveChanges leaveChanges,
  required MessagesApiClient messagesApiClient,
  required NotificationsApiClient notificationsApiClient,
  required AnnouncementsApiClient announcementsApiClient,
  required InboxChanges inboxChanges,
}) {
  // Notifications and Announcements (Phase 32) open from Home and from
  // More: the same pages under each tab, so Back stays in that tab.
  List<RouteBase> inboxRoutes(String base) => [
    GoRoute(
      path: 'notifications',
      builder: (context, state) => NotificationsPage(
        notificationsApiClient: notificationsApiClient,
        inboxChanges: inboxChanges,
        base: base,
      ),
    ),
    GoRoute(
      path: 'announcements',
      builder: (context, state) => AnnouncementsPage(
        announcementsApiClient: announcementsApiClient,
        inboxChanges: inboxChanges,
        base: base,
      ),
      routes: [
        GoRoute(
          path: ':publicId',
          builder: (context, state) => AnnouncementPage(
            key: ValueKey('$base-${state.pathParameters['publicId']}'),
            announcementsApiClient: announcementsApiClient,
            inboxChanges: inboxChanges,
            publicId: state.pathParameters['publicId']!,
            args: state.extra is AnnouncementArgs
                ? state.extra! as AnnouncementArgs
                : null,
          ),
        ),
      ],
    ),
  ];

  // A leave request's detail (Phase 31) — mine or a direct report's.
  Widget leaveRequest(GoRouterState state, LeaveRequestViewer viewer) =>
      LeaveRequestPage(
        key: ValueKey('${viewer.name}-${state.pathParameters['publicId']}'),
        leaveApiClient: leaveApiClient,
        leaveChanges: leaveChanges,
        publicId: state.pathParameters['publicId']!,
        viewer: viewer,
        args: state.extra is LeaveRequestArgs
            ? state.extra! as LeaveRequestArgs
            : null,
      );

  // The work-log form (Phase 29B) — the same page from My work logs and
  // from a task's detail; its route `extra` says which log or task.
  Widget workLogForm(GoRouterState state, {WorkLogFormArgs? fallback}) =>
      WorkLogFormPage(
        workLogsApiClient: workLogsApiClient,
        tasksApiClient: tasksApiClient,
        workLogChanges: workLogChanges,
        args: state.extra is WorkLogFormArgs
            ? state.extra! as WorkLogFormArgs
            : fallback,
      );

  return GoRouter(
    initialLocation: '/splash',
    refreshListenable: authController,
    redirect: (context, state) => _redirect(authController, state),
    routes: [
      GoRoute(
        path: '/splash',
        builder: (context, state) => const _SplashPage(),
      ),
      GoRoute(
        path: '/login',
        builder: (context, state) => LoginPage(controller: authController),
      ),
      StatefulShellRoute.indexedStack(
        builder: (context, state, navigationShell) =>
            AppShell(navigationShell: navigationShell),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/home',
                builder: (context, state) => HomePage(
                  homeApiClient: homeApiClient,
                  taskChanges: taskChanges,
                  inboxChanges: inboxChanges,
                ),
                routes: inboxRoutes('/home'),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              // Tasks (Phase 29A): the detail stays inside the Tasks branch,
              // so Home's task links switch to this tab (spec §5.3).
              GoRoute(
                path: '/tasks',
                builder: (context, state) => TasksPage(
                  tasksApiClient: tasksApiClient,
                  taskChanges: taskChanges,
                ),
                routes: [
                  GoRoute(
                    path: ':publicId',
                    builder: (context, state) => TaskDetailPage(
                      key: ValueKey(state.pathParameters['publicId']),
                      tasksApiClient: tasksApiClient,
                      taskChanges: taskChanges,
                      publicId: state.pathParameters['publicId']!,
                      args: state.extra is TaskDetailArgs
                          ? state.extra! as TaskDetailArgs
                          : null,
                    ),
                    routes: [
                      // "Log work" on a task (Phase 29B, R-15): the form
                      // stays in the Tasks branch, above the task.
                      GoRoute(
                        path: 'log-work',
                        builder: (context, state) => workLogForm(
                          state,
                          fallback: WorkLogFormArgs(
                            fixedTarget: TaskTarget(
                              publicId: state.pathParameters['publicId']!,
                              label: 'This task',
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              // Schedule (Phase 30A): the week agenda, and a read-only entry
              // detail inside the same branch — Home's meeting rows switch
              // to this tab (R-10), as its task rows switch to Tasks.
              GoRoute(
                path: '/schedule',
                builder: (context, state) => SchedulePage(
                  scheduleApiClient: scheduleApiClient,
                  taskChanges: taskChanges,
                  leaveChanges: leaveChanges,
                ),
                routes: [
                  GoRoute(
                    path: 'entries/:publicId',
                    builder: (context, state) => ScheduleEntryPage(
                      key: ValueKey(state.pathParameters['publicId']),
                      scheduleApiClient: scheduleApiClient,
                      publicId: state.pathParameters['publicId']!,
                      args: state.extra is ScheduleEntryArgs
                          ? state.extra! as ScheduleEntryArgs
                          : null,
                    ),
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              // Messages (Phase 32, R-3/R-4): the conversations list, New
              // message (registered before the id route), and a chat.
              GoRoute(
                path: '/messages',
                builder: (context, state) => ConversationsPage(
                  messagesApiClient: messagesApiClient,
                  inboxChanges: inboxChanges,
                ),
                routes: [
                  GoRoute(
                    path: 'new',
                    builder: (context, state) => NewMessagePage(
                      messagesApiClient: messagesApiClient,
                      peopleApiClient: peopleApiClient,
                      myStaffId: state.extra is ChatArgs
                          ? (state.extra! as ChatArgs).myStaffId
                          : null,
                    ),
                  ),
                  GoRoute(
                    path: ':publicId',
                    builder: (context, state) => ChatPage(
                      key: ValueKey(state.pathParameters['publicId']),
                      messagesApiClient: messagesApiClient,
                      inboxChanges: inboxChanges,
                      publicId: state.pathParameters['publicId']!,
                      args: state.extra is ChatArgs
                          ? state.extra! as ChatArgs
                          : null,
                    ),
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              // People (Phase 28): every sub-page stays inside the More
              // branch, so the tab keeps its own stack across tab switches.
              GoRoute(
                path: '/more',
                builder: (context, state) =>
                    MorePage(myStatusApiClient: myStatusApiClient),
                routes: [
                  ...inboxRoutes('/more'),
                  // My status (Phase 30B, R-13): the screen, its two "All"
                  // lists and the check-in flow, all in the More branch.
                  GoRoute(
                    path: 'status',
                    builder: (context, state) =>
                        MyStatusPage(myStatusApiClient: myStatusApiClient),
                    routes: [
                      GoRoute(
                        path: 'check-ins',
                        builder: (context, state) =>
                            CheckInsPage(myStatusApiClient: myStatusApiClient),
                      ),
                      GoRoute(
                        path: 'history',
                        builder: (context, state) => StatusHistoryPage(
                          myStatusApiClient: myStatusApiClient,
                        ),
                      ),
                      GoRoute(
                        path: 'check-in',
                        builder: (context, state) => CheckInPage(
                          myStatusApiClient: myStatusApiClient,
                          locationService: locationService,
                        ),
                      ),
                    ],
                  ),
                  // Leave (Phase 31, R-1): my leave, Request leave, my
                  // requests, and Team requests — all in the More branch.
                  // `new` and `team` are registered before any id route.
                  GoRoute(
                    path: 'leave',
                    builder: (context, state) => LeavePage(
                      leaveApiClient: leaveApiClient,
                      leaveChanges: leaveChanges,
                    ),
                    routes: [
                      GoRoute(
                        path: 'new',
                        builder: (context, state) => LeaveFormPage(
                          leaveApiClient: leaveApiClient,
                          leaveChanges: leaveChanges,
                          args: state.extra is LeaveFormArgs
                              ? state.extra! as LeaveFormArgs
                              : null,
                        ),
                      ),
                      GoRoute(
                        path: 'requests/:publicId',
                        builder: (context, state) =>
                            leaveRequest(state, LeaveRequestViewer.mine),
                      ),
                      GoRoute(
                        path: 'team',
                        builder: (context, state) => TeamLeavePage(
                          leaveApiClient: leaveApiClient,
                          leaveChanges: leaveChanges,
                        ),
                        routes: [
                          GoRoute(
                            path: ':publicId',
                            builder: (context, state) =>
                                leaveRequest(state, LeaveRequestViewer.team),
                          ),
                        ],
                      ),
                    ],
                  ),
                  GoRoute(
                    path: 'profile',
                    builder: (context, state) =>
                        MyProfilePage(peopleApiClient: peopleApiClient),
                  ),
                  // My work logs (Phase 29B): `new` is registered before
                  // the `:publicId` edit route so it is never read as an id.
                  GoRoute(
                    path: 'work-logs',
                    builder: (context, state) => MyWorkLogsPage(
                      workLogsApiClient: workLogsApiClient,
                      workLogChanges: workLogChanges,
                    ),
                    routes: [
                      GoRoute(
                        path: 'new',
                        builder: (context, state) => workLogForm(state),
                      ),
                      GoRoute(
                        path: ':publicId',
                        builder: (context, state) => workLogForm(state),
                      ),
                    ],
                  ),
                  // Projects and Clients (Phase 29C): inside the More branch
                  // too, so a project's client and members open here as
                  // well — and a task's project link switches to this tab
                  // (spec §7.5, R-25).
                  GoRoute(
                    path: 'projects',
                    builder: (context, state) =>
                        ProjectsPage(projectsApiClient: projectsApiClient),
                    routes: [
                      GoRoute(
                        path: ':publicId',
                        builder: (context, state) => ProjectDetailPage(
                          key: ValueKey(state.pathParameters['publicId']),
                          projectsApiClient: projectsApiClient,
                          publicId: state.pathParameters['publicId']!,
                        ),
                      ),
                    ],
                  ),
                  GoRoute(
                    path: 'clients',
                    builder: (context, state) =>
                        ClientsPage(clientsApiClient: clientsApiClient),
                    routes: [
                      GoRoute(
                        path: ':publicId',
                        builder: (context, state) => ClientDetailPage(
                          key: ValueKey(state.pathParameters['publicId']),
                          clientsApiClient: clientsApiClient,
                          publicId: state.pathParameters['publicId']!,
                        ),
                      ),
                    ],
                  ),
                  GoRoute(
                    path: 'directory',
                    builder: (context, state) =>
                        StaffDirectoryPage(peopleApiClient: peopleApiClient),
                    routes: [
                      GoRoute(
                        path: ':publicId',
                        builder: (context, state) => StaffDetailPage(
                          // A new key per person, so opening a manager
                          // from a detail page builds a fresh page.
                          key: ValueKey(state.pathParameters['publicId']),
                          peopleApiClient: peopleApiClient,
                          publicId: state.pathParameters['publicId']!,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    ],
  );
}

/// Unauthenticated -> `/login`; authenticated -> the shell (`/home` by
/// default); [AuthStatus.unknown] (bootstrap still resolving the stored
/// token) -> `/splash`, the router-level equivalent of [AuthGate]'s old
/// `CircularProgressIndicator` Scaffold. Each branch only redirects when
/// not already at the target location, so a settled state produces no
/// further redirect — the loop-prevention this router relies on.
String? _redirect(AuthController authController, GoRouterState state) {
  final status = authController.status;
  final location = state.matchedLocation;

  if (status == AuthStatus.unknown) {
    return location == '/splash' ? null : '/splash';
  }

  final authenticated = status == AuthStatus.authenticated;

  if (!authenticated) {
    return location == '/login' ? null : '/login';
  }

  if (location == '/login' || location == '/splash') {
    return '/home';
  }

  return null;
}

class _SplashPage extends StatelessWidget {
  const _SplashPage();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(body: Center(child: CircularProgressIndicator()));
  }
}

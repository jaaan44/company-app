import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:mobile/app/auth_scope.dart';
import 'package:mobile/app/router.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/data/token_storage.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/clients/data/clients_api_client.dart';
import 'package:mobile/features/announcements/data/announcements_api_client.dart';
import 'package:mobile/features/home/data/home_api_client.dart';
import 'package:mobile/features/messages/data/messages_api_client.dart';
import 'package:mobile/features/notifications/data/notifications_api_client.dart';
import 'package:mobile/features/notifications/state/inbox_changes.dart';
import 'package:mobile/features/leave/data/leave_api_client.dart';
import 'package:mobile/features/leave/state/leave_changes.dart';
import 'package:mobile/features/my_status/data/my_status_api_client.dart';
import 'package:mobile/features/my_status/location/location_service.dart';
import 'package:mobile/features/people/data/people_api_client.dart';
import 'package:mobile/features/projects/data/projects_api_client.dart';
import 'package:mobile/features/reports/data/photo_source.dart';
import 'package:mobile/features/reports/data/reports_api_client.dart';
import 'package:mobile/features/reports/state/report_changes.dart';
import 'package:mobile/features/schedule/data/schedule_api_client.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/state/task_changes.dart';
import 'package:mobile/features/work_logs/data/work_logs_api_client.dart';
import 'package:mobile/features/work_logs/state/work_log_changes.dart';

/// Root widget of the Company App staff mobile application.
///
/// As of Phase 25 (Mobile Application Foundation & Navigation Shell), this
/// owns the single [AuthController] instance for the app's lifetime (as
/// before) plus the single [GoRouter] instance built from it —
/// `go_router`'s `StatefulShellRoute`-based bottom-navigation shell is now
/// the app's sole navigation mechanism, replacing the former [AuthGate]
/// widget-switch with declarative, route-level auth redirects (see
/// lib/app/router.dart). [AuthScope] makes the same [AuthController]
/// available to every route the router builds. No routing package was
/// used before this phase (DEC-021's deferral, resolved by DEC-046), and
/// no third-party state-management framework is introduced now either —
/// a plain [ChangeNotifier] remains sufficient; see DEC-025.
class CompanyApp extends StatefulWidget {
  /// [authController], [homeApiClient], [peopleApiClient],
  /// [tasksApiClient], [workLogsApiClient], [projectsApiClient],
  /// [clientsApiClient], [scheduleApiClient], [myStatusApiClient],
  /// [locationService], [leaveApiClient], [messagesApiClient],
  /// [notificationsApiClient], [announcementsApiClient], [reportsApiClient]
  /// and [photoSource] are exposed
  /// for tests to inject fakes — production code always omits them and
  /// gets the real Sanctum-backed implementations below.
  const CompanyApp({
    super.key,
    AuthController? authController,
    HomeApiClient? homeApiClient,
    PeopleApiClient? peopleApiClient,
    TasksApiClient? tasksApiClient,
    WorkLogsApiClient? workLogsApiClient,
    ProjectsApiClient? projectsApiClient,
    ClientsApiClient? clientsApiClient,
    ScheduleApiClient? scheduleApiClient,
    MyStatusApiClient? myStatusApiClient,
    LocationService? locationService,
    LeaveApiClient? leaveApiClient,
    MessagesApiClient? messagesApiClient,
    NotificationsApiClient? notificationsApiClient,
    AnnouncementsApiClient? announcementsApiClient,
    ReportsApiClient? reportsApiClient,
    PhotoSource? photoSource,
  }) : _injectedAuthController = authController,
       _injectedHomeApiClient = homeApiClient,
       _injectedPeopleApiClient = peopleApiClient,
       _injectedTasksApiClient = tasksApiClient,
       _injectedWorkLogsApiClient = workLogsApiClient,
       _injectedProjectsApiClient = projectsApiClient,
       _injectedClientsApiClient = clientsApiClient,
       _injectedScheduleApiClient = scheduleApiClient,
       _injectedMyStatusApiClient = myStatusApiClient,
       _injectedLocationService = locationService,
       _injectedLeaveApiClient = leaveApiClient,
       _injectedMessagesApiClient = messagesApiClient,
       _injectedNotificationsApiClient = notificationsApiClient,
       _injectedAnnouncementsApiClient = announcementsApiClient,
       _injectedReportsApiClient = reportsApiClient,
       _injectedPhotoSource = photoSource;

  final AuthController? _injectedAuthController;
  final HomeApiClient? _injectedHomeApiClient;
  final PeopleApiClient? _injectedPeopleApiClient;
  final TasksApiClient? _injectedTasksApiClient;
  final WorkLogsApiClient? _injectedWorkLogsApiClient;
  final ProjectsApiClient? _injectedProjectsApiClient;
  final ClientsApiClient? _injectedClientsApiClient;
  final ScheduleApiClient? _injectedScheduleApiClient;
  final MyStatusApiClient? _injectedMyStatusApiClient;
  final LocationService? _injectedLocationService;
  final LeaveApiClient? _injectedLeaveApiClient;
  final MessagesApiClient? _injectedMessagesApiClient;
  final NotificationsApiClient? _injectedNotificationsApiClient;
  final AnnouncementsApiClient? _injectedAnnouncementsApiClient;
  final ReportsApiClient? _injectedReportsApiClient;
  final PhotoSource? _injectedPhotoSource;

  @override
  State<CompanyApp> createState() => _CompanyAppState();
}

class _CompanyAppState extends State<CompanyApp> {
  late final AuthController _authController =
      widget._injectedAuthController ??
      AuthController(
        apiClient: AuthApiClient(),
        tokenStorage: SecureTokenStorage(),
      );

  // The authenticated API client (Phase 27) takes its token and session
  // rules from the same AuthController; every feature client shares it.
  late final ApiClient _apiClient = ApiClient(session: _authController);

  late final HomeApiClient _homeApiClient =
      widget._injectedHomeApiClient ?? HomeApiClient(_apiClient);

  late final PeopleApiClient _peopleApiClient =
      widget._injectedPeopleApiClient ?? PeopleApiClient(_apiClient);

  late final TasksApiClient _tasksApiClient =
      widget._injectedTasksApiClient ?? TasksApiClient(_apiClient);

  // Confirmed task saves (Phase 29A): the Tasks lists and Home listen, so
  // they refresh after a status change made on a detail screen.
  final TaskChanges _taskChanges = TaskChanges();

  late final WorkLogsApiClient _workLogsApiClient =
      widget._injectedWorkLogsApiClient ?? WorkLogsApiClient(_apiClient);

  // Confirmed work-log saves and deletions (Phase 29B), so My work logs
  // refreshes after a log made from a task's detail.
  final WorkLogChanges _workLogChanges = WorkLogChanges();

  late final ProjectsApiClient _projectsApiClient =
      widget._injectedProjectsApiClient ?? ProjectsApiClient(_apiClient);

  late final ClientsApiClient _clientsApiClient =
      widget._injectedClientsApiClient ?? ClientsApiClient(_apiClient);

  late final ScheduleApiClient _scheduleApiClient =
      widget._injectedScheduleApiClient ?? ScheduleApiClient(_apiClient);

  late final MyStatusApiClient _myStatusApiClient =
      widget._injectedMyStatusApiClient ?? MyStatusApiClient(_apiClient);

  late final LocationService _locationService =
      widget._injectedLocationService ?? GeolocatorLocationService();

  late final LeaveApiClient _leaveApiClient =
      widget._injectedLeaveApiClient ?? LeaveApiClient(_apiClient);

  // Confirmed leave changes (Phase 31, R-11): the Leave screens and
  // Schedule reload, so approved leave appears without a manual refresh.
  final LeaveChanges _leaveChanges = LeaveChanges();

  late final MessagesApiClient _messagesApiClient =
      widget._injectedMessagesApiClient ?? MessagesApiClient(_apiClient);

  late final NotificationsApiClient _notificationsApiClient =
      widget._injectedNotificationsApiClient ??
      NotificationsApiClient(_apiClient);

  late final AnnouncementsApiClient _announcementsApiClient =
      widget._injectedAnnouncementsApiClient ??
      AnnouncementsApiClient(_apiClient);

  // Confirmed communication changes (Phase 32, R-11): Home's counts,
  // Messages, Notifications and Announcements reload.
  final InboxChanges _inboxChanges = InboxChanges();

  late final ReportsApiClient _reportsApiClient =
      widget._injectedReportsApiClient ?? ReportsApiClient(_apiClient);

  late final PhotoSource _photoSource =
      widget._injectedPhotoSource ?? ImagePickerPhotoSource();

  // Confirmed report changes (Phase 33, R-11): both lists reload.
  final ReportChanges _reportChanges = ReportChanges();

  late final GoRouter _router = buildAppRouter(
    _authController,
    homeApiClient: _homeApiClient,
    peopleApiClient: _peopleApiClient,
    tasksApiClient: _tasksApiClient,
    taskChanges: _taskChanges,
    workLogsApiClient: _workLogsApiClient,
    workLogChanges: _workLogChanges,
    projectsApiClient: _projectsApiClient,
    clientsApiClient: _clientsApiClient,
    scheduleApiClient: _scheduleApiClient,
    myStatusApiClient: _myStatusApiClient,
    locationService: _locationService,
    leaveApiClient: _leaveApiClient,
    leaveChanges: _leaveChanges,
    messagesApiClient: _messagesApiClient,
    notificationsApiClient: _notificationsApiClient,
    announcementsApiClient: _announcementsApiClient,
    inboxChanges: _inboxChanges,
    reportsApiClient: _reportsApiClient,
    photoSource: _photoSource,
    reportChanges: _reportChanges,
  );

  @override
  void initState() {
    super.initState();
    // Kicks off the stored-token check every app launch (formerly
    // AuthGate.initState) — the router's redirect shows `/splash` until
    // this resolves, avoiding a flash of the wrong screen.
    _authController.bootstrap();
  }

  @override
  void dispose() {
    _router.dispose();
    _taskChanges.dispose();
    _workLogChanges.dispose();
    _leaveChanges.dispose();
    _inboxChanges.dispose();
    _reportChanges.dispose();
    _authController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AuthScope(
      controller: _authController,
      child: MaterialApp.router(
        title: 'Company App',
        theme: ThemeData(
          colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo),
        ),
        darkTheme: ThemeData(
          colorScheme: ColorScheme.fromSeed(
            seedColor: Colors.indigo,
            brightness: Brightness.dark,
          ),
        ),
        themeMode: ThemeMode.system,
        routerConfig: _router,
      ),
    );
  }
}

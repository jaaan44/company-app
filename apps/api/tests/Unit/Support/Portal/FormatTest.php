<?php

namespace Tests\Unit\Support\Portal;

use App\Support\Portal\Format;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The portal's display helpers read the same as the mobile app's
 * (home_formatting.dart, people labels), in the company timezone.
 */
class FormatTest extends TestCase
{
    /** @var array<string, string> a Manila company day, 2026-09-24 */
    private const DAY = [
        'date' => '2026-09-24',
        'starts_at' => '2026-09-23T16:00:00+00:00',
        'ends_at' => '2026-09-24T15:59:59+00:00',
        'utc_offset' => '+08:00',
    ];

    public function test_today_time_in_company_time(): void
    {
        $this->assertSame('09:00 – 10:30', Format::todayTime(['is_all_day' => false, 'starts_at' => '2026-09-24T01:00:00+00:00', 'ends_at' => '2026-09-24T02:30:00+00:00'], self::DAY));
        $this->assertSame('All day', Format::todayTime(['is_all_day' => true, 'starts_at' => 'x', 'ends_at' => 'x'], self::DAY));
    }

    public function test_today_time_for_entries_crossing_the_day(): void
    {
        $this->assertSame('From Wed 23 Sep – 10:30', Format::todayTime(['is_all_day' => false, 'starts_at' => '2026-09-23T01:00:00+00:00', 'ends_at' => '2026-09-24T02:30:00+00:00'], self::DAY));
        $this->assertSame('09:00 – until Fri 25 Sep', Format::todayTime(['is_all_day' => false, 'starts_at' => '2026-09-24T01:00:00+00:00', 'ends_at' => '2026-09-25T02:30:00+00:00'], self::DAY));
    }

    public function test_labels(): void
    {
        $this->assertSame('Meeting', Format::todayLabel(['source_type' => 'schedule_entry', 'activity_type' => 'meeting']));
        $this->assertSame('Task due · In progress', Format::todayLabel(['source_type' => 'task', 'task_status' => 'in_progress']));
        $this->assertSame('Field work', Format::activityType('field_work'));
        $this->assertSame('Scheduled', Format::activityType(null));
        $this->assertSame('Administrator', Format::role('administrator'));
        $this->assertNull(Format::role(null));
    }

    public function test_dates_and_names(): void
    {
        $this->assertSame('1 Mar 2024', Format::date('2024-03-01'));
        $this->assertNull(Format::date(null));
        $this->assertSame('Thu 24 Sep', Format::dayMonth(CarbonImmutable::parse('2026-09-24')));
        $this->assertSame('Technician · Operations', Format::roleLine(['position' => ['title' => 'Technician'], 'department' => ['name' => 'Operations']]));
        $this->assertSame('Operations', Format::roleLine(['position' => null, 'department' => ['name' => 'Operations']]));
        $this->assertNull(Format::roleLine(['position' => null, 'department' => null]));
        $this->assertSame('Amelia Ramos', Format::distinctFullName(['first_name' => 'Amelia', 'last_name' => 'Ramos', 'display_name' => 'Lia Ramos']));
        $this->assertNull(Format::distinctFullName(['first_name' => 'Kai', 'last_name' => 'Tan', 'display_name' => 'Kai Tan']));
    }

    public function test_task_due_labels_like_mobile(): void
    {
        $today = '2026-10-13';
        $this->assertNull(Format::taskDueLabel(['due_date' => null, 'status' => 'todo'], $today));
        $this->assertSame('Due today', Format::taskDueLabel(['due_date' => '2026-10-13', 'status' => 'todo'], $today));
        $this->assertSame('Overdue · 1 Oct', Format::taskDueLabel(['due_date' => '2026-10-01', 'status' => 'blocked'], $today));
        $this->assertSame('Due 2 Nov', Format::taskDueLabel(['due_date' => '2026-11-02', 'status' => 'todo'], $today));
        $this->assertSame('Due 3 Jan 2027', Format::taskDueLabel(['due_date' => '2027-01-03', 'status' => 'todo'], $today));
        $this->assertSame('Due 1 Oct', Format::taskDueLabel(['due_date' => '2026-10-01', 'status' => 'completed'], $today), 'a closed task is never overdue');
        $this->assertSame('overdue', Format::taskDueState(['due_date' => '2026-12-01', 'status' => 'todo', 'is_overdue' => true, 'is_due_today' => false], $today), 'the list\'s own flags win');
    }

    public function test_durations_and_day_labels(): void
    {
        $this->assertSame('45 min', Format::duration(45));
        $this->assertSame('2 h', Format::duration(120));
        $this->assertSame('1 h 30 min', Format::duration(90));
        $this->assertSame('Today', Format::dayLabel('2026-10-13', '2026-10-13'));
        $this->assertSame('Yesterday', Format::dayLabel('2026-10-12', '2026-10-13'));
        $this->assertSame('Wed 14 Oct', Format::dayLabel('2026-10-14', '2026-10-13'));
        $this->assertSame('Tomorrow', Format::dayLabel('2026-10-14', '2026-10-13', withTomorrow: true));
        $this->assertSame('Tue 7 Oct 2025', Format::dayLabel('2025-10-07', '2026-10-13'));
        $this->assertSame('13 – 19 Oct', Format::weekRange('2026-10-13', '2026-10-19'));
        $this->assertSame('28 Oct – 3 Nov', Format::weekRange('2026-10-28', '2026-11-03'));
    }

    public function test_schedule_items_on_company_days(): void
    {
        $meeting = ['is_all_day' => false, 'starts_at' => '2026-10-13T15:00:00+00:00', 'ends_at' => '2026-10-14T01:00:00+00:00'];
        // 23:00 Tue – 09:00 Wed Manila: on both days, each with its own label.
        $this->assertTrue(Format::overlapsDay($meeting, '2026-10-13', '+08:00'));
        $this->assertTrue(Format::overlapsDay($meeting, '2026-10-14', '+08:00'));
        $this->assertFalse(Format::overlapsDay($meeting, '2026-10-15', '+08:00'));
        $this->assertSame('23:00 – until Wed 14 Oct', Format::itemTimeOn($meeting, '2026-10-13', '+08:00'));
        $this->assertSame('From Tue 13 Oct – 09:00', Format::itemTimeOn($meeting, '2026-10-14', '+08:00'));
        // Ending exactly at midnight belongs to the day before only.
        $toMidnight = ['is_all_day' => false, 'starts_at' => '2026-10-13T14:00:00+00:00', 'ends_at' => '2026-10-13T16:00:00+00:00'];
        $this->assertFalse(Format::overlapsDay($toMidnight, '2026-10-14', '+08:00'));
        $this->assertSame('Wed 14 Oct · 09:00 – 10:30', Format::entryWhen(['is_all_day' => false, 'starts_at' => '2026-10-14T01:00:00+00:00', 'ends_at' => '2026-10-14T02:30:00+00:00'], '+08:00'));
        $this->assertSame('Task · Blocked', Format::scheduleLabel(['source_type' => 'task', 'task_status' => 'blocked']));
        $this->assertSame('Leave', Format::scheduleLabel(['source_type' => 'leave']));
    }

    public function test_addresses_and_tones(): void
    {
        $this->assertSame(['1 Dock Rd', 'Pasig, NCR 1600', 'PH'], Format::addressLines(['address_line1' => '1 Dock Rd', 'address_line2' => ' ', 'city' => 'Pasig', 'state_province' => 'NCR', 'postal_code' => '1600', 'country' => 'PH']));
        $this->assertSame([], Format::addressLines([]));
        $this->assertSame('danger', Format::tone('blocked'));
        $this->assertSame('success', Format::tone('completed'));
        $this->assertSame('muted', Format::tone('cancelled'));
        $this->assertSame('neutral', Format::tone('todo'));
    }

    public function test_company_time_relative_to_the_company_day(): void
    {
        $this->assertSame('Today 09:12', Format::companyTime('2026-10-13T01:12:00+00:00', '2026-10-13', '+08:00'));
        $this->assertSame('Today 00:05', Format::companyTime('2026-10-12T16:05:00+00:00', '2026-10-13', '+08:00'));
        $this->assertSame('Yesterday 18:40', Format::companyTime('2026-10-12T10:40:00+00:00', '2026-10-13', '+08:00'));
        $this->assertSame('Thu 1 Oct 08:30', Format::companyTime('2026-10-01T00:30:00+00:00', '2026-10-13', '+08:00'));
        $this->assertSame('30 Dec 2025 08:30', Format::companyTime('2025-12-30T00:30:00+00:00', '2026-01-02', '+08:00'));
    }

    public function test_leave_labels(): void
    {
        $this->assertSame('1 day', Format::days(1));
        $this->assertSame('0 days', Format::days(0));
        $this->assertSame('Tue 20 Oct 2026', Format::leaveDates('2026-10-20', '2026-10-20'));
        $this->assertSame('Tue 20 Oct – Thu 22 Oct 2026', Format::leaveDates('2026-10-20', '2026-10-22'));
        $this->assertSame('Thu 31 Dec 2026 – Fri 1 Jan 2027', Format::leaveDates('2026-12-31', '2027-01-01'));
        $this->assertSame('1 calendar day (weekends included)', Format::calendarDays(1));
        $this->assertSame('3 calendar days (weekends included)', Format::calendarDays(3));
        $this->assertSame('10 days left of 15', Format::balanceHeadline(['is_paid' => true, 'allocated_days' => 15, 'remaining_days' => 10]));
        $this->assertSame('1 day left of 5', Format::balanceHeadline(['is_paid' => true, 'allocated_days' => 5, 'remaining_days' => 1]));
        $this->assertSame('Unpaid — no balance', Format::balanceHeadline(['is_paid' => false, 'allocated_days' => null, 'remaining_days' => null]));
        $this->assertSame('Pending', Format::leaveStatus('pending'));
        $this->assertSame('Cancelled', Format::leaveStatus('cancelled'));
        $this->assertSame('Unknown', Format::leaveStatus('odd'));
        $this->assertSame('Requested', Format::leaveAction('submitted'));
        $this->assertSame('Rejected', Format::leaveAction('rejected'));
        $this->assertSame('Updated', Format::leaveAction(null));
    }

    public function test_operational_statuses(): void
    {
        $this->assertSame('Available', Format::operationalStatus('available'));
        $this->assertSame('In a meeting', Format::operationalStatus('in_meeting'));
        $this->assertSame('In the field', Format::operationalStatus('in_field'));
        $this->assertSame('Off duty', Format::operationalStatus('off_duty'));
        $this->assertSame('Unknown status', Format::operationalStatus(null));
    }

    public function test_inbox_and_chat_times(): void
    {
        $this->assertSame('09:12', Format::inboxTime('2026-10-13T01:12:00+00:00', '2026-10-13', '+08:00'));
        $this->assertSame('00:05', Format::inboxTime('2026-10-12T16:05:00+00:00', '2026-10-13', '+08:00'));
        $this->assertSame('Yesterday', Format::inboxTime('2026-10-12T10:40:00+00:00', '2026-10-13', '+08:00'));
        $this->assertSame('Tue 1 Sep', Format::inboxTime('2026-09-01T00:00:00+00:00', '2026-10-13', '+08:00'));
        $this->assertSame('30 Dec 2025', Format::inboxTime('2025-12-30T00:00:00+00:00', '2026-01-02', '+08:00'));
        $this->assertSame('Today', Format::chatDay('2026-10-13', '2026-10-13'));
        $this->assertSame('Yesterday', Format::chatDay('2026-10-12', '2026-10-13'));
        $this->assertSame('Mon 5 Oct', Format::chatDay('2026-10-05', '2026-10-13'));
        $this->assertSame('31 Dec 2025', Format::chatDay('2025-12-31', '2026-01-02'));
        $this->assertSame('12 Oct 2026 09:30', Format::dateTime('2026-10-12T01:30:00+00:00', '+08:00'));
    }

    public function test_conversation_titles_and_previews(): void
    {
        $members = [['staff' => ['public_id' => 'ME', 'name' => 'Lia Ramos']], ['staff' => ['public_id' => 'K', 'name' => 'Kai Tan']]];
        $direct = ['type' => 'direct', 'name' => null, 'members' => $members, 'last_message' => ['body' => "See you\n  at the dock", 'sender' => ['public_id' => 'K', 'name' => 'Kai Tan']]];
        $group = ['type' => 'group', 'name' => 'Crew', 'members' => $members, 'last_message' => ['body' => 'Hi', 'sender' => ['public_id' => 'K', 'name' => 'Kai Tan']]];
        $project = ['type' => 'project', 'name' => null, 'project' => ['name' => 'Plant 2'], 'members' => $members, 'last_message' => ['body' => 'Done', 'sender' => ['public_id' => 'ME', 'name' => 'Lia Ramos']]];

        $this->assertSame('Kai Tan', Format::conversationTitle($direct, 'ME'));
        $this->assertSame('Crew', Format::conversationTitle($group, 'ME'));
        $this->assertSame('Plant 2', Format::conversationTitle($project, 'ME'));
        $this->assertSame('Conversation', Format::conversationTitle(['type' => 'direct', 'members' => [$members[0]]], 'ME'));
        $this->assertSame('See you at the dock', Format::lastMessagePreview($direct, 'ME'));
        $this->assertSame('Kai: Hi', Format::lastMessagePreview($group, 'ME'));
        $this->assertSame('You: Done', Format::lastMessagePreview($project, 'ME'));
        $this->assertSame('No messages yet', Format::lastMessagePreview(['type' => 'group', 'last_message' => null], 'ME'));
        $this->assertTrue(Format::showsSenderNames($project));
        $this->assertFalse(Format::showsSenderNames($direct));
    }
}

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
}

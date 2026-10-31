<?php

namespace Tests\Unit;

use App\Services\Scholarship\AttendanceWindow;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class AttendanceWindowTest extends TestCase
{
    /**
     * @dataProvider boundaryProvider
     */
    public function test_ending_at_resolves_semester_start_to_reference_date(
        string $reference,
        string $expectedStart,
        string $expectedEnd
    ): void {
        $window = AttendanceWindow::endingAt(Carbon::parse($reference));

        $this->assertSame($expectedStart, $window->start);
        $this->assertSame($expectedEnd, $window->end);
    }

    public static function boundaryProvider(): array
    {
        return [
            'last day of first semester' => ['2026-07-31', '2026-01-01', '2026-07-31'],
            'first day of second semester' => ['2026-08-01', '2026-08-01', '2026-08-01'],
            'late december' => ['2026-12-29', '2026-08-01', '2026-12-29'],
            'first day of year' => ['2027-01-01', '2027-01-01', '2027-01-01'],
        ];
    }

    public function test_time_of_day_is_ignored(): void
    {
        $window = AttendanceWindow::endingAt(Carbon::parse('2026-10-29 23:59:59'));

        $this->assertSame('2026-08-01', $window->start);
        $this->assertSame('2026-10-29', $window->end);
    }
}

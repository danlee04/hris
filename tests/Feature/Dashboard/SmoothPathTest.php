<?php

namespace Tests\Feature\Dashboard;

use App\Services\Dashboard\SmoothPath;
use PHPUnit\Framework\TestCase;

class SmoothPathTest extends TestCase
{
    private SmoothPath $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = new SmoothPath;
    }

    /**
     * Every y the curve passes through, sampled along each cubic segment.
     *
     * @param  list<array{x: float|int, y: float|int}>  $points
     * @return list<float>
     */
    private function sample(array $points, int $per = 40): array
    {
        $d = $this->path->curve($points);

        preg_match_all('/C ([\d.-]+),([\d.-]+) ([\d.-]+),([\d.-]+) ([\d.-]+),([\d.-]+)/', $d, $matches, PREG_SET_ORDER);

        $ys = [];
        $from = (float) $points[0]['y'];

        foreach ($matches as $segment) {
            [$y1, $y2, $y3] = [(float) $segment[2], (float) $segment[4], (float) $segment[6]];

            for ($step = 0; $step <= $per; $step++) {
                $t = $step / $per;
                $ys[] = (1 - $t) ** 3 * $from
                    + 3 * (1 - $t) ** 2 * $t * $y1
                    + 3 * (1 - $t) * $t ** 2 * $y2
                    + $t ** 3 * $y3;
            }

            $from = $y3;
        }

        return $ys;
    }

    public function test_it_needs_two_points_to_draw_anything(): void
    {
        $this->assertSame('', $this->path->curve([]));
        $this->assertSame('', $this->path->curve([['x' => 0, 'y' => 10]]));
    }

    public function test_it_starts_at_the_first_point_and_ends_at_the_last(): void
    {
        $d = $this->path->curve([
            ['x' => 0, 'y' => 100],
            ['x' => 50, 'y' => 40],
            ['x' => 100, 'y' => 70],
        ]);

        $this->assertStringStartsWith('M 0,100', $d);
        $this->assertStringEndsWith('100,70', $d);
    }

    public function test_a_peak_between_two_empty_months_never_dips_below_the_baseline(): void
    {
        // The whole reason this class exists. In SVG a larger y is lower, so the
        // baseline is the largest y in the data: a curve that goes past it has
        // drawn a negative number of leave applications.
        $baseline = 130.0;

        $ys = $this->sample([
            ['x' => 0, 'y' => $baseline],
            ['x' => 50, 'y' => 20],
            ['x' => 100, 'y' => $baseline],
            ['x' => 150, 'y' => $baseline],
        ]);

        $this->assertLessThanOrEqual($baseline + 0.001, max($ys));
    }

    public function test_it_does_not_swell_above_the_highest_point(): void
    {
        $ys = $this->sample([
            ['x' => 0, 'y' => 130],
            ['x' => 50, 'y' => 60],
            ['x' => 100, 'y' => 20],
            ['x' => 150, 'y' => 60],
        ]);

        $this->assertGreaterThanOrEqual(20 - 0.001, min($ys));
    }

    public function test_a_flat_run_stays_flat(): void
    {
        // Six months of nothing is a straight line on the baseline, not a
        // gentle wave suggesting activity that did not happen.
        $ys = $this->sample([
            ['x' => 0, 'y' => 130],
            ['x' => 50, 'y' => 130],
            ['x' => 100, 'y' => 130],
        ]);

        foreach ($ys as $y) {
            $this->assertEqualsWithDelta(130.0, $y, 0.001);
        }
    }

    public function test_it_passes_through_every_point_it_is_given(): void
    {
        $points = [
            ['x' => 0, 'y' => 130],
            ['x' => 50, 'y' => 90],
            ['x' => 100, 'y' => 30],
        ];

        $ys = $this->sample($points);

        foreach ($points as $point) {
            $this->assertTrue(
                collect($ys)->contains(fn (float $y) => abs($y - $point['y']) < 0.01),
                "The curve misses the point at y={$point['y']}."
            );
        }
    }
}

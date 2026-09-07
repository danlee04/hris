<?php

namespace App\Services\Dashboard;

/**
 * Turns a run of points into a smooth SVG path.
 *
 * The obvious curve — Catmull-Rom, or any plain cubic through the points — is
 * wrong here. It lets the line overshoot between points, so a month with two
 * applications sitting between two months with none draws a curve that dips
 * *below* the baseline on its way there. The chart would show a negative number
 * of leave applications, and it would look deliberate.
 *
 * This is the monotone cubic of Fritsch and Carlson: the tangents are clamped
 * so the curve can never turn back on itself between two points. Where the data
 * rises the curve rises, where it is flat the curve is flat, and it never leaves
 * the range its own points set.
 */
class SmoothPath
{
    /**
     * @param  list<array{x: float|int, y: float|int}>  $points
     * @return string an SVG path `d`, or an empty string for fewer than two points
     */
    public function curve(array $points): string
    {
        $points = array_values($points);
        $n = count($points);

        if ($n < 2) {
            return '';
        }

        $slopes = $this->tangents($points);
        $path = sprintf('M %s,%s', $this->round($points[0]['x']), $this->round($points[0]['y']));

        for ($i = 0; $i < $n - 1; $i++) {
            $dx = $points[$i + 1]['x'] - $points[$i]['x'];

            // Hermite tangents become Bezier handles a third of the way along.
            $path .= sprintf(
                ' C %s,%s %s,%s %s,%s',
                $this->round($points[$i]['x'] + $dx / 3),
                $this->round($points[$i]['y'] + $slopes[$i] * $dx / 3),
                $this->round($points[$i + 1]['x'] - $dx / 3),
                $this->round($points[$i + 1]['y'] - $slopes[$i + 1] * $dx / 3),
                $this->round($points[$i + 1]['x']),
                $this->round($points[$i + 1]['y']),
            );
        }

        return $path;
    }

    /**
     * One tangent per point, clamped so no segment can overshoot.
     *
     * @param  list<array{x: float|int, y: float|int}>  $points
     * @return list<float>
     */
    private function tangents(array $points): array
    {
        $n = count($points);
        $deltas = [];

        for ($i = 0; $i < $n - 1; $i++) {
            $dx = $points[$i + 1]['x'] - $points[$i]['x'];
            $deltas[$i] = $dx == 0.0 ? 0.0 : ($points[$i + 1]['y'] - $points[$i]['y']) / $dx;
        }

        $slopes = [$deltas[0]];

        for ($i = 1; $i < $n - 1; $i++) {
            // A turning point gets a flat tangent. Averaging across it is what
            // makes a curve bulge past the data on either side.
            $slopes[$i] = $deltas[$i - 1] * $deltas[$i] <= 0
                ? 0.0
                : ($deltas[$i - 1] + $deltas[$i]) / 2;
        }

        $slopes[$n - 1] = $deltas[$n - 2];

        for ($i = 0; $i < $n - 1; $i++) {
            if ($deltas[$i] == 0.0) {
                // A flat run stays flat at both ends, or the curve would swell
                // between two equal values.
                $slopes[$i] = 0.0;
                $slopes[$i + 1] = 0.0;

                continue;
            }

            $alpha = $slopes[$i] / $deltas[$i];
            $beta = $slopes[$i + 1] / $deltas[$i];
            $magnitude = $alpha ** 2 + $beta ** 2;

            // Outside a radius of three the segment is no longer monotone.
            // Scaling both tangents back onto the circle is what keeps it so.
            if ($magnitude > 9) {
                $scale = 3 / sqrt($magnitude);
                $slopes[$i] = $scale * $alpha * $deltas[$i];
                $slopes[$i + 1] = $scale * $beta * $deltas[$i];
            }
        }

        return $slopes;
    }

    private function round(float|int $value): string
    {
        return (string) round((float) $value, 2);
    }
}

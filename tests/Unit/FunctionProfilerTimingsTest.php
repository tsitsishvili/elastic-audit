<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use ReflectionMethod;
use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;
use Tsitsishvili\ElasticAudit\Services\ExcimerFunctionProfiler;
use Tsitsishvili\ElasticAudit\Services\XhprofFunctionProfiler;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

/** Neither extension is loaded in CI, so the drivers' post-processing is exercised directly. */
final class FunctionProfilerTimingsTest extends TestCase
{
    public function test_excimer_counts_a_recursive_frame_once_per_sample(): void
    {
        $payload = [
            'shared'   => ['frames' => [['name' => '{main}'], ['name' => 'App\\Tree::walk'], ['name' => 'App\\Tree::leaf']]],
            'profiles' => [[
                'type'    => 'sampled',
                'samples' => [[0, 1, 1, 1, 2], [0, 1, 1, 1, 2]],
                'weights' => [1, 1],
            ]],
        ];

        [, , $hotFrames, , $timings] = (new ReflectionMethod(ExcimerFunctionProfiler::class, 'normalize'))
            ->invoke(new ExcimerFunctionProfiler(periodMs: 10.0), $payload);

        $walk = collect($timings)->firstWhere('function', 'App\\Tree::walk');
        $leaf = collect($hotFrames)->firstWhere('function', 'App\\Tree::leaf');

        // Two samples of a 10 ms period, however deep the recursion went.
        $this->assertSame(20.0, $walk['total_ms']);
        $this->assertSame(2, $leaf['total_samples']);
    }

    public function test_excimer_counts_a_blocking_sample_by_its_weight_not_as_one_period(): void
    {
        // What Excimer emits: a 600 ms sleep is one sample weighing 600 ms, a CPU sample weighs one period.
        $payload = [
            'shared'   => ['frames' => [['name' => '{main}'], ['name' => 'blocking_io'], ['name' => 'cpu_loop']]],
            'profiles' => [[
                'type'    => 'sampled',
                'unit'    => 'nanoseconds',
                'samples' => [[0, 1], [0, 2]],
                'weights' => [600_000_000, 10_000_000],
            ]],
        ];

        [, , , , $timings] = (new ReflectionMethod(ExcimerFunctionProfiler::class, 'normalize'))
            ->invoke(new ExcimerFunctionProfiler(periodMs: 10.0), $payload);
        $timings = collect($timings)->keyBy('function');

        $this->assertSame(600.0, $timings['blocking_io']['total_ms']);
        $this->assertSame(10.0, $timings['cpu_loop']['total_ms']);
        $this->assertSame(610.0, $timings['{main}']['total_ms']);
    }

    public function test_xhprof_derives_self_time_from_outbound_edges_and_ranks_hot_frames_by_it(): void
    {
        $raw = [
            'main()'                              => ['ct' => 1, 'wt' => 100_000],
            'main()==>App\\Order::place'          => ['ct' => 1, 'wt' => 90_000],
            'App\\Order::place==>App\\Repo::save' => ['ct' => 1, 'wt' => 60_000],
            'App\\Order::place==>App\\Str::tiny'  => ['ct' => 500, 'wt' => 5_000],
        ];

        /** @var CapturedProfile $capture */
        $capture = (new ReflectionMethod(XhprofFunctionProfiler::class, 'capture'))
            ->invoke(new XhprofFunctionProfiler, $raw);
        $timings = collect($capture->functionTimings)->keyBy('function');

        $this->assertSame(25.0, $timings['App\\Order::place']['self_ms']);
        $this->assertSame(60.0, $timings['App\\Repo::save']['self_ms']);
        $this->assertSame(10.0, $timings['main()']['self_ms']);

        // Ranked by exclusive time, not by the 500 calls to a cheap helper.
        $this->assertSame('App\\Repo::save', $capture->hotFrames[0]['function']);
        $this->assertSame(60_000, $capture->hotFrames[0]['self_samples']);
    }
}

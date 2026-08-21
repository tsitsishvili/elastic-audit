<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Support\ProfileSourcePath;

class ProfileSourcePathTest extends TestCase
{
    public function test_closure_frame_names_do_not_leak_absolute_paths_when_paths_are_disabled(): void
    {
        $this->assertSame(
            '{closure:(426)}',
            ProfileSourcePath::sanitizeName(
                '{closure:/srv/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(426)}',
                false,
            ),
        );

        $this->assertSame(
            '{closure:}',
            ProfileSourcePath::sanitizeName('{closure:C:\\srv\\app\\src\\Reports.php:12}', false),
        );
    }

    public function test_namespaced_class_names_are_never_treated_as_paths(): void
    {
        foreach ([
            'Illuminate\\Database\\Connectors\\MySqlConnector::connect',
            'App\\Http\\Controllers\\OrderController::store',
            'Illuminate\\Http\\Resources\\Json\\JsonResource::removeMissingValues',
            '{closure}',
            'strlen',
        ] as $name) {
            $this->assertSame($name, ProfileSourcePath::sanitizeName($name, false));
            $this->assertSame($name, ProfileSourcePath::sanitizeName($name, true));
        }
    }

    public function test_enabling_paths_keeps_a_basename_outside_the_application_root(): void
    {
        // base_path() is unavailable in a plain unit test, so every absolute
        // path falls back to its basename.
        $this->assertSame(
            '{closure:Connection.php(426)}',
            ProfileSourcePath::sanitizeName(
                '{closure:/srv/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(426)}',
                true,
            ),
        );
    }
}

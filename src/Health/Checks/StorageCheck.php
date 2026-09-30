<?php

namespace GonbiDigital\Cooee\Health\Checks;

use GonbiDigital\Cooee\Health\CheckResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A full disk, expired S3 credentials or a read-only mount all show up as nothing at all over
 * HTTP, right up until the first upload of the day fails.
 */
class StorageCheck implements Check
{
    use Timed;

    public function name(): string
    {
        return 'storage';
    }

    public function applies(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        return $this->timed($this->name(), function (): string {
            $name = (string) (config('cooee.health.storage.disk') ?: config('filesystems.default'));
            $disk = Storage::disk($name);
            $path = 'cooee-health/'.Str::random(16).'.txt';
            $token = Str::random(16);

            try {
                $disk->put($path, $token);
                $read = $disk->get($path);
            } finally {
                // Always, including when the read threw: a probe that litters a bucket with a file
                // per check is its own incident a month later.
                $disk->delete($path);
            }

            if ($read !== $token) {
                throw new RuntimeException('Wrote a file to the '.$name.' disk and read back something else.');
            }

            return 'read back what was written to the '.$name.' disk';
        });
    }
}

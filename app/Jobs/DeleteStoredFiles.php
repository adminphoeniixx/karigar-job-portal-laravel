<?php

namespace App\Jobs;

use App\Models\ScreeningCall;
use App\Services\BunnyCdn;
use App\Services\Screening\CallRecordings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The files of a deleted account: KYC documents and résumé on the private
 * disk, photo and logo on the public CDN, screening call recordings. Queued,
 * so the delete request does not wait on the CDN. A file that will not go is
 * reported and skipped; its row is already gone, so a retry would not help.
 */
class DeleteStoredFiles implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $privatePaths
     * @param  list<string>  $publicPaths
     * @param  list<array{id: int, path: string}>  $recordings
     */
    public function __construct(
        public array $privatePaths,
        public array $publicPaths,
        public array $recordings = [],
    ) {}

    public function handle(CallRecordings $calls): void
    {
        $disk = Storage::disk('private');

        foreach ($this->privatePaths as $path) {
            $this->attempt(fn () => $disk->delete($path));
        }

        foreach ($this->publicPaths as $path) {
            $this->attempt(fn () => BunnyCdn::delete($path));
        }

        foreach ($this->recordings as $recording) {
            // The row is gone; its id and path are all delete() reads.
            $call = (new ScreeningCall)->forceFill(['id' => $recording['id'], 'recording_path' => $recording['path']]);
            $this->attempt(fn () => $calls->delete($call));
        }
    }

    private function attempt(callable $delete): void
    {
        try {
            $delete();
        } catch (Throwable $e) {
            report($e);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Services\KycSubmission;
use App\Services\ResumeStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copies resumes and KYC documents still on this machine's local disk onto
 * the 'private' disk (BunnyCDN), keeping their paths, so the rows that point
 * at them keep working. Files already there are skipped, so it is safe to run
 * any number of times. Run it where the files are: before a deploy throws the
 * container's disk away.
 */
class MovePrivateFiles extends Command
{
    protected $signature = 'files:move-private';

    protected $description = 'Copy local resumes and KYC documents onto the private (BunnyCDN) disk';

    public function handle(): int
    {
        $local = Storage::disk('local');
        $private = Storage::disk(KycSubmission::DISK);

        if ($private->getConfig()['driver'] === 'local') {
            $this->error('The private disk is the local disk here; BunnyCDN is not configured.');

            return self::FAILURE;
        }

        $copied = $skipped = 0;

        foreach ([ResumeStore::DIRECTORY, 'kyc'] as $directory) {
            foreach ($local->allFiles($directory) as $path) {
                if ($private->exists($path)) {
                    $skipped++;

                    continue;
                }

                $private->writeStream($path, $local->readStream($path));
                $copied++;
                $this->line("copied {$path}");
            }
        }

        $this->info("{$copied} copied, {$skipped} already there.");

        return self::SUCCESS;
    }
}

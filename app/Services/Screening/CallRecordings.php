<?php

namespace App\Services\Screening;

use App\Models\ScreeningCall;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The audio of an AI screening call: the agent records both voices, mixes
 * them to one mono MP3 and uploads it once the call is over.
 *
 * Kept on the 'private' disk (BunnyCDN, same as resumes and KYC): a worker's
 * voice is personal data, so it is only ever streamed through our own
 * controller, behind a short-lived signed link, never as a CDN URL.
 */
class CallRecordings
{
    public const DISK = 'private';

    public const DIRECTORY = 'call-recordings';

    /** How long a "play recording" link works. */
    public const LINK_MINUTES = 120;

    /**
     * Save (or replace) the call's recording.
     */
    public function put(ScreeningCall $call, UploadedFile $file): void
    {
        $path = self::DIRECTORY.'/'.$call->id.'-'.$call->provider_call_id.'.mp3';

        Storage::disk(self::DISK)->putFileAs(self::DIRECTORY, $file, basename($path));

        $this->forgetLocalCopy($call);
        $call->update(['recording_path' => $path]);
    }

    /**
     * A link the web player and the app can both open without a session or a
     * token header, which phone audio players cannot send reliably.
     */
    public function url(ScreeningCall $call): ?string
    {
        if (blank($call->recording_path)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.screening.recording',
            now()->addMinutes(self::LINK_MINUTES),
            ['call' => $call->id],
        );
    }

    /**
     * The recording as a local file, fetched from Bunny on first play.
     *
     * Served from disk rather than streamed through, because a file response
     * answers byte-range requests and an iPhone's player refuses to play audio
     * from a server that does not. The copy is only a cache: the container
     * loses it on deploy and the next play fetches it again.
     */
    public function localCopy(ScreeningCall $call): ?string
    {
        if (blank($call->recording_path)) {
            return null;
        }

        $cache = $this->cachePath($call);

        if (! is_file($cache)) {
            $stream = Storage::disk(self::DISK)->readStream($call->recording_path);

            if ($stream === null) {
                return null;
            }

            if (! is_dir(dirname($cache))) {
                mkdir(dirname($cache), 0755, true);
            }

            // Written aside and moved in, so a second play arriving mid-download
            // never serves half a file.
            $partial = $cache.'.'.uniqid().'.part';
            file_put_contents($partial, $stream);
            fclose($stream);
            rename($partial, $cache);
        }

        return $cache;
    }

    public function delete(ScreeningCall $call): void
    {
        if (filled($call->recording_path)) {
            Storage::disk(self::DISK)->delete($call->recording_path);
        }

        $this->forgetLocalCopy($call);
    }

    private function forgetLocalCopy(ScreeningCall $call): void
    {
        if (is_file($cache = $this->cachePath($call))) {
            unlink($cache);
        }
    }

    private function cachePath(ScreeningCall $call): string
    {
        return storage_path('app/recording-cache/'.$call->id.'.mp3');
    }
}

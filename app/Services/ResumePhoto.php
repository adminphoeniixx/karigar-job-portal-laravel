<?php

namespace App\Services;

use App\Models\WorkerProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The karigar's profile photo, cut into a circle for the resume PDF.
 *
 * mPDF cannot round an image's corners, so the circle is baked into a PNG with
 * a transparent edge. The avatar lives on BunnyCDN; it is fetched once per
 * avatar and the round copy is kept on the local disk (mPDF needs a file
 * path; a wiped copy is just made again), so a live preview that re-renders
 * on every edit does not fetch it again.
 */
class ResumePhoto
{
    public const DIRECTORY = 'resume-photos';

    /** Output size in pixels — sharp at the ~35 mm the templates print it. */
    private const SIZE = 480;

    /**
     * Absolute path to the round PNG, or null when there is no usable photo.
     */
    public function path(?WorkerProfile $profile): ?string
    {
        $avatar = $profile?->avatar_path;

        if (! $avatar || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $disk = Storage::disk('local');
        $file = self::DIRECTORY.'/'.sha1($avatar).'.png';

        if (! $disk->exists($file)) {
            $png = $this->round($this->fetch($profile));
            if ($png === null) {
                return null;
            }
            $disk->put($file, $png);
        }

        return $disk->path($file);
    }

    private function fetch(WorkerProfile $profile): ?string
    {
        $url = $profile->avatar_url;

        if (! $url) {
            return null;
        }

        try {
            $response = Http::timeout(8)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable $e) {
            Log::warning('ResumePhoto could not fetch the avatar: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Centre-crop to a square and mask everything outside the circle. Drawn at
     * twice the size and scaled down, which smooths the edge.
     */
    private function round(?string $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $big = self::SIZE * 2;

        $square = imagecreatetruecolor($big, $big);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagecopyresampled($square, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $big, $big, $side, $side);

        $clear = imagecolorallocatealpha($square, 0, 0, 0, 127);
        $r = $big / 2;
        for ($y = 0; $y < $big; $y++) {
            for ($x = 0; $x < $big; $x++) {
                if (($x - $r + 0.5) ** 2 + ($y - $r + 0.5) ** 2 > $r * $r) {
                    imagesetpixel($square, $x, $y, $clear);
                }
            }
        }

        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $square, 0, 0, 0, 0, self::SIZE, self::SIZE, $big, $big);

        ob_start();
        imagepng($out);
        $png = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($square);
        imagedestroy($out);

        return $png;
    }
}

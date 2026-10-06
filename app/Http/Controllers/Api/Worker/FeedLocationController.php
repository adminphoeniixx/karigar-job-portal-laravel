<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Services\Geocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the job feed looks around. By default it is wherever the phone is;
 * the karigar can pick another place instead (a town they are moving to, the
 * area of a site), and go back to "my current location" any time. The choice
 * is kept on the profile, so the feed stays on it until they change it.
 */
class FeedLocationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'location' => $request->user()->workerProfile()->firstOrCreate([])->feedLocation(),
        ]);
    }

    /**
     * Places for the picker's search box. Call it when the karigar stops
     * typing (or presses search), not on every key: the map service behind it
     * allows about one request a second.
     */
    public function places(Request $request, Geocoder $geocoder): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        return response()->json(['places' => $geocoder->search($data['q'])]);
    }

    /**
     * Pick a place: one from `places` (send its latitude, longitude and
     * label), or a pin dropped on the map (latitude and longitude; the label
     * is looked up).
     */
    public function update(Request $request, Geocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'label' => ['nullable', 'string', 'max:150'],
        ]);

        $label = trim((string) ($data['label'] ?? ''))
            ?: ($geocoder->reverse((float) $data['latitude'], (float) $data['longitude'])['label'] ?? __('Pinned location'));

        $profile = $request->user()->workerProfile()->firstOrCreate([]);
        $profile->update([
            'feed_location_label' => $label,
            'feed_latitude' => $data['latitude'],
            'feed_longitude' => $data['longitude'],
        ]);

        return response()->json([
            'message' => __('Showing jobs near :place.', ['place' => $label]),
            'location' => $profile->feedLocation(),
        ]);
    }

    /**
     * Back to "my current location".
     */
    public function destroy(Request $request): JsonResponse
    {
        $profile = $request->user()->workerProfile()->firstOrCreate([]);
        $profile->update(['feed_location_label' => null, 'feed_latitude' => null, 'feed_longitude' => null]);

        return response()->json([
            'message' => __('Showing jobs near your current location.'),
            'location' => $profile->feedLocation(),
        ]);
    }
}

<?php

use App\Enums\ScreeningCallStatus;
use App\Models\JobListing;
use App\Models\ScreeningCall;
use App\Models\User;
use App\Services\Geocoder;
use App\Services\Screening\ScreeningService;
use App\Support\LocateByCity;
use App\Support\WageConversion;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A job plan runs out on a date, with no event to react to, so the jobs of
// employers whose plan lapsed are taken out of search here (and put back once
// they renew, if the payment hook missed it). Search already hides them in
// the meantime; this keeps the index and its counts honest.
Artisan::command('jobs:sync-hiring', function () {
    $employers = User::whereHas('jobListings', fn ($q) => $q->active())->get();

    $employers->each(fn (User $employer) => JobListing::syncSearchFor($employer));

    $this->info("Synced the live jobs of {$employers->count()} employers with their plans.");
})->purpose('Take paused jobs out of search and put renewed ones back');

Schedule::command('jobs:sync-hiring')->hourly();

// New karigars who typed a city but dropped no pin get the city centre, so
// Find Workers can show how far away they are.
Schedule::command('karigars:locate --sync')->hourly()->withoutOverlapping();

// AI screening calls booked for a time (by the employer, or asked for outside
// calling hours) are dialled here once that time comes.
Artisan::command('screening:place-due', function (ScreeningService $screening) {
    $due = ScreeningCall::where('status', ScreeningCallStatus::Scheduled)
        ->where('scheduled_at', '<=', now())
        ->orderBy('scheduled_at')
        ->limit(50)
        ->get();

    $due->each(fn (ScreeningCall $call) => $screening->placeScheduled($call));

    $this->info("Placed {$due->count()} scheduled screening calls.");
})->purpose('Dial the AI screening calls whose booked time has come');

Schedule::command('screening:place-due')->everyMinute()->withoutOverlapping();

// Wages are monthly (App\Support\Wage). The migration converted what was
// there; run this after deploying to catch rows older code wrote in between,
// with --sync on the server to refresh the karigar search index.
Artisan::command('wages:to-monthly {--sync : Also re-sync real karigars in search}', function () {
    $counts = WageConversion::run();
    $this->info("Converted to monthly: {$counts['jobs']} jobs, {$counts['applications']} applications, {$counts['profiles']} karigar profiles.");

    if ($this->option('sync')) {
        $synced = WageConversion::syncSearch();
        $synced === null
            ? $this->warn('Search could not be reached; nothing was re-synced.')
            : $this->info("Re-synced {$synced} karigars in search.");
    }
})->purpose('Turn daily and hourly wages into monthly ones');

// Karigars and employers with a city but no map pin: place them at the city
// centre so distances, "nearest" and the radius filter work for them.
Artisan::command('karigars:locate {--sync : Also re-sync the placed karigars in search} {--all : With --sync, re-sync every real karigar, for rows placed before search could be reached}', function (Geocoder $geocoder) {
    $counts = LocateByCity::run($geocoder, (bool) $this->option('sync'));

    if ($this->option('sync') && $this->option('all')) {
        $counts['synced'] = WageConversion::syncSearch();
    }
    $this->info("Placed {$counts['workers']} karigars and {$counts['employers']} employers in {$counts['cities']} cities.");

    if ($counts['unmatched'] !== []) {
        $this->warn('No match for: '.implode('; ', $counts['unmatched']));
    }

    if ($this->option('sync')) {
        $counts['synced'] === null
            ? $this->warn('Search could not be reached; nothing was re-synced.')
            : $this->info("Re-synced {$counts['synced']} karigars in search.");
    }
})->purpose('Give karigars and employers without a map pin their city centre');

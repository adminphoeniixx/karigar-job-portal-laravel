<?php

use App\Models\JobListing;
use App\Models\User;
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

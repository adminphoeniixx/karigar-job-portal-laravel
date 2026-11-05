// Indian states/UTs → every city and town (~10,000, every district included),
// A–Z. Used for the state→city dependent dropdowns on job filters, the job
// posting form, and profiles.
//
// indianCities.json is generated together with resources/data/indian-cities.php
// (served by GET /api/v1/reference/cities), so web and app list the same cities.
import cities from './indianCities.json';

export const citiesByState: Record<string, string[]> = cities;

export const indianStates: string[] = Object.keys(citiesByState).sort();

export function citiesFor(state: string | null | undefined): string[] {
    if (!state) return [];
    return citiesByState[state] ?? [];
}

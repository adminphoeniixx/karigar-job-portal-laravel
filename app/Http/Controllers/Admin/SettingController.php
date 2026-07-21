<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'first_post_free_enabled' => Setting::bool('first_post_free_enabled', true),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_post_free_enabled' => ['required', 'boolean'],
        ]);

        Setting::set('first_post_free_enabled', $data['first_post_free_enabled'] ? '1' : '0');

        return back()->with('toast', ['type' => 'success', 'message' => __('Settings updated.')]);
    }
}

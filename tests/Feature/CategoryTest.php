<?php

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
});

it('lets an admin view the categories page', function () {
    $this->actingAs($this->admin)->get('/admin/categories')->assertOk();
});

it('lets an admin add a category', function () {
    $this->actingAs($this->admin)->post('/admin/categories', ['name' => 'Roofing'])->assertRedirect();

    expect(Category::where('name', 'Roofing')->where('is_active', true)->exists())->toBeTrue()
        ->and(Category::firstWhere('name', 'Roofing')->slug)->toBe('roofing');
});

it('rejects a duplicate category name', function () {
    Category::create(['name' => 'Plumbing']);
    $this->actingAs($this->admin)->post('/admin/categories', ['name' => 'Plumbing'])
        ->assertSessionHasErrors('name');
});

it('lets an admin hide (deactivate) and delete a category', function () {
    $c = Category::create(['name' => 'Welding']);

    $this->actingAs($this->admin)->patch("/admin/categories/{$c->id}", ['is_active' => false])->assertRedirect();
    expect($c->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->admin)->delete("/admin/categories/{$c->id}")->assertRedirect();
    expect(Category::find($c->id))->toBeNull();
});

it('forbids a non-admin from managing categories', function () {
    $this->actingAs($this->employer)->get('/admin/categories')->assertForbidden();
    $this->actingAs($this->employer)->post('/admin/categories', ['name' => 'Hacking'])->assertForbidden();
});

it('only exposes active categories via activeNames and busts cache on change', function () {
    Cache::forget('categories.active');
    Category::create(['name' => 'Painting', 'is_active' => true]);
    Category::create(['name' => 'Hidden Cat', 'is_active' => false]);

    expect(Category::activeNames())->toContain('Painting')
        ->and(Category::activeNames())->not->toContain('Hidden Cat');
});

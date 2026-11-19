<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CustomJobTitle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    /** How many job titles one category can offer on the job form. */
    private const MAX_JOB_TITLES = 40;

    public function index(): Response
    {
        return Inertia::render('admin/Categories', [
            'categories' => Category::orderBy('name')->get(['id', 'name', 'slug', 'skills', 'job_titles', 'is_active']),
            // Titles employers typed that no category lists yet.
            'customTitles' => CustomJobTitle::forAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:categories,name'],
        ]);

        Category::create(['name' => $data['name'], 'is_active' => true]);
        $this->flush();

        return back()->with('toast', ['type' => 'success', 'message' => __('Category added.')]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', Rule::unique('categories', 'name')->ignore($category->id)],
            'is_active' => ['sometimes', 'boolean'],
            // Suggested on the job form once this category is picked.
            'skills' => ['sometimes', 'array', 'max:30'],
            // A blank tag arrives as null; it is dropped below, not refused.
            'skills.*' => ['nullable', 'string', 'max:50'],
            // Job titles offered on the job form; picking one suggests this category.
            'job_titles' => ['sometimes', 'array', 'max:'.self::MAX_JOB_TITLES],
            'job_titles.*' => ['nullable', 'string', 'max:60'],
        ]);

        foreach (['skills', 'job_titles'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = collect($data[$list])->map(fn ($s) => trim((string) $s))->filter()->unique()->values()->all();
            }
        }

        $category->update($data);
        $this->flush();

        return back()->with('toast', ['type' => 'success', 'message' => __('Category updated.')]);
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();
        $this->flush();

        return back()->with('toast', ['type' => 'success', 'message' => __('Category deleted.')]);
    }

    /**
     * Adds a title employers typed to a category's job titles, so the job form
     * offers it to everyone from now on. It then leaves the list of new titles.
     */
    public function addCustomTitle(Request $request, CustomJobTitle $customJobTitle): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            // The admin may tidy it first ("knitting master" → "Knitting Master").
            'title' => ['required', 'string', 'max:60'],
        ]);

        $category = Category::findOrFail($data['category_id']);
        $title = trim($data['title']);
        $titles = $category->job_titles ?? [];
        $listed = collect($titles)->contains(fn (string $t) => CustomJobTitle::normalise($t) === CustomJobTitle::normalise($title));

        if (! $listed) {
            if (count($titles) >= self::MAX_JOB_TITLES) {
                return back()->with('toast', ['type' => 'error', 'message' => __(':category already has :max job titles. Remove one first.', [
                    'category' => $category->name,
                    'max' => self::MAX_JOB_TITLES,
                ])]);
            }

            $category->update(['job_titles' => [...$titles, $title]]);
        }

        $customJobTitle->delete();
        $this->flush();

        return back()->with('toast', ['type' => 'success', 'message' => __('":title" added to :category.', [
            'title' => $title,
            'category' => $category->name,
        ])]);
    }

    /**
     * Hides a title for good: the same title typed again stays hidden.
     */
    public function hideCustomTitle(CustomJobTitle $customJobTitle): RedirectResponse
    {
        $customJobTitle->update(['hidden_at' => now()]);

        return back()->with('toast', ['type' => 'success', 'message' => __('":title" hidden. It will not come back.', [
            'title' => $customJobTitle->title,
        ])]);
    }

    private function flush(): void
    {
        Category::flushCache();
    }
}

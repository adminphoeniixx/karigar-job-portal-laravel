<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Categories', [
            'categories' => Category::orderBy('name')->get(['id', 'name', 'slug', 'is_active']),
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
        ]);

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

    private function flush(): void
    {
        Cache::forget('categories.active');
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ClaimCategory;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    private const FIELDS = ['name', 'description', 'keywords', 'requires_receipt', 'is_active'];

    public function __construct(private AuditLogger $audit) {}

    public function index()
    {
        return view('settings.categories.index', [
            'categories' => ClaimCategory::withCount('claims')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('settings.categories.form', ['category' => new ClaimCategory(['is_active' => true, 'requires_receipt' => true])]);
    }

    public function store(Request $request)
    {
        $category = ClaimCategory::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->audit->log('category.created', 'claim_category', $category->id, null, $category->only(self::FIELDS));

        return redirect()->route('settings.categories.index')->with('success', 'Category created.');
    }

    public function edit(ClaimCategory $category)
    {
        return view('settings.categories.form', compact('category'));
    }

    public function update(Request $request, ClaimCategory $category)
    {
        $before = $category->only(self::FIELDS);
        $category->update($this->validated($request, $category));
        [$old, $new] = $this->audit->diff($before, $category->only(self::FIELDS));
        if ($new !== []) {
            $this->audit->log('category.updated', 'claim_category', $category->id, $old, $new);
        }

        return redirect()->route('settings.categories.index')->with('success', 'Category updated.');
    }

    private function validated(Request $request, ?ClaimCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('claim_categories')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['nullable', 'string', 'max:5000'],
        ]) + [
            'requires_receipt' => $request->boolean('requires_receipt'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}

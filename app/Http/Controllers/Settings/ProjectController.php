<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    private const FIELDS = ['project_code', 'name', 'customer_name', 'description', 'is_active'];

    public function __construct(private AuditLogger $audit) {}

    public function index()
    {
        return view('settings.projects.index', [
            'projects' => Project::withCount('claims')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('settings.projects.form', ['project' => new Project(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        $project = Project::create($this->validated($request));
        $this->audit->log('project.created', 'project', $project->id, null, $project->only(self::FIELDS));

        return redirect()->route('settings.projects.index')->with('success', 'Project created.');
    }

    public function edit(Project $project)
    {
        return view('settings.projects.form', compact('project'));
    }

    public function update(Request $request, Project $project)
    {
        $before = $project->only(self::FIELDS);
        $project->update($this->validated($request, $project));
        [$old, $new] = $this->audit->diff($before, $project->only(self::FIELDS));
        if ($new !== []) {
            $this->audit->log('project.updated', 'project', $project->id, $old, $new);
        }

        return redirect()->route('settings.projects.index')->with('success', 'Project updated.');
    }

    private function validated(Request $request, ?Project $project = null): array
    {
        return $request->validate([
            'project_code' => ['nullable', 'string', 'max:50', Rule::unique('projects')->ignore($project?->id)],
            'name' => ['required', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}

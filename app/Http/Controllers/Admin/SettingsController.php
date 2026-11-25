<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Services\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        protected SystemSettingsService $settings
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Settings/Index', [
            'settings' => $this->settings->formPayload(),
            'defaults' => $this->settings->defaultPayload(),
            'meta' => [
                'lastUpdated' => $this->settings->lastUpdatedMeta(),
                'skillCounts' => [
                    'basic' => count(config('siasurf.skill_assessment.skills.basic', [])),
                    'intermediate' => count(config('siasurf.skill_assessment.skills.intermediate', [])),
                    'advanced' => count(config('siasurf.skill_assessment.skills.advanced', [])),
                    'questions' => count(config('siasurf.skill_assessment.questions', [])),
                ],
                'outcomeOptions' => IncidentOutcome::decisionOptions(),
                'concernCategories' => FeedbackCategory::concernOptions(),
            ],
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request): RedirectResponse
    {
        $this->settings->updateFromPayload($request->validated(), $request->user()?->id);

        return back()->with('success', 'System settings updated and applied.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'group' => ['nullable', 'string', 'in:rates,booking,refunds,skill_assessment,incident_severity'],
        ]);

        $group = $validated['group'] ?? null;
        $this->settings->resetToDefaults($request->user()?->id, $group);

        $message = $group !== null
            ? 'Selected settings section restored to defaults.'
            : 'All system settings restored to defaults.';

        return back()->with('success', $message);
    }
}

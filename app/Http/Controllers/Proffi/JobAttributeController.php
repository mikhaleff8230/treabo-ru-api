<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiTask;
use App\Services\JobAttributeService;
use Illuminate\Http\Request;

class JobAttributeController extends Controller
{
    public function __construct(private readonly JobAttributeService $attributes)
    {
    }

    public function show(ProffiTask $job)
    {
        return $this->attributes->valuesForJob($job->id);
    }

    public function store(Request $request, ProffiTask $job)
    {
        $data = $request->validate([
            'values' => ['required', 'array'],
        ]);

        return $this->attributes->save($job->id, $data['values']);
    }
}

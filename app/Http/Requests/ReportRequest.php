<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reports.view') ?? false;
    }

    public function rules(): array
    {
        return ['period' => ['sometimes', Rule::in(['today', 'yesterday', 'week', 'month', 'custom'])],
            'start_date' => ['required_if:period,custom', 'prohibited_unless:period,custom', 'date_format:Y-m-d'],
            'end_date' => ['required_if:period,custom', 'prohibited_unless:period,custom', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}

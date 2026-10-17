<?php

namespace App\Http\Requests\Area;

use App\Http\Requests\Concerns\HabitRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHabitRequest extends FormRequest
{
    use HabitRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->habitRules(partial: true);
    }
}

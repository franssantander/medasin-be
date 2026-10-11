<?php

namespace App\Console\Commands;

use App\Enum\PlanGrantType;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plan\PlanAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Signature('plans:assign {user_uuid} {plan_slug} {--reference=} {--expires-at=}')]
#[Description('Assign a product plan with an idempotent reference and explicit access period')]
class AssignPlan extends Command
{
    public function handle(PlanAssignmentService $assignments): int
    {
        try {
            $input = Validator::make([
                'user_uuid' => $this->argument('user_uuid'),
                'plan_slug' => $this->argument('plan_slug'),
                'reference' => $this->option('reference'),
                'expires_at' => $this->option('expires-at'),
            ], [
                'user_uuid' => ['required', 'uuid'],
                'plan_slug' => ['required', 'string'],
                'reference' => ['required', 'string', 'max:120'],
                'expires_at' => ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/'],
            ])->validate();
            $user = User::query()->where('uuid', $input['user_uuid'])->firstOrFail();
            $plan = Plan::withTrashed()->where('slug', $input['plan_slug'])->firstOrFail();
            $kind = $plan->slug === 'free' ? PlanGrantType::FREE : PlanGrantType::RECURRING;
            $assignment = $assignments->assign(
                $user, $plan, $kind,
                isset($input['expires_at']) ? CarbonImmutable::parse($input['expires_at']) : null,
                $input['reference'],
            );
            $this->info('Plan assignment: '.$assignment->uuid);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(implode(' ', Arr::flatten($exception->errors())));
        } catch (ModelNotFoundException) {
            $this->error('The account or product plan could not be found.');
        }

        return self::FAILURE;
    }
}

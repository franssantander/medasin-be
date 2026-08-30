<?php

namespace App\Http\Requests\Board;

class UpdateBoardTaskRequest extends StoreBoardTaskRequest
{
    public function rules(): array
    {
        return $this->taskRules(true);
    }
}

<?php

namespace App\Http\Requests;

class UpdateAdminMovieRequest extends StoreAdminMovieRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->movieRules(false) + $this->protectedRules();
    }
}

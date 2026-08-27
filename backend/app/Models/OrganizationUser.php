<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * role's exact string value set is not documented anywhere in the current
 * design docs (database-design.md defers to a "prior design" not reproduced
 * there) — left as a plain string, not enum-cast, until that's resolved.
 */
#[Fillable(['role'])]
class OrganizationUser extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_number', 'name', 'contact_name', 'phone', 'email', 'address', 'postal_code', 'city', 'notes'])]
class Customer extends Model
{
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}

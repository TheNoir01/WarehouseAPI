<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function types(): HasMany
    {
        return $this->hasMany(ItemType::class, 'category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}

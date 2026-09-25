<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'return_number',
        'stock_issue_id',
        'company_id',
        'returned_date',
        'returned_by_name',
        'received_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'returned_date' => 'date',
        ];
    }

    public function stockIssue(): BelongsTo
    {
        return $this->belongsTo(StockIssue::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReturnItem::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id')
            ->where('entity_type', StockReturn::class);
    }
}

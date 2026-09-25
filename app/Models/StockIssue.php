<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockIssue extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'issue_number',
        'company_id',
        'project_name',
        'requester_name',
        'recipient_name',
        'issued_date',
        'issued_by',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockIssueItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(StockReturn::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id')
            ->where('entity_type', StockIssue::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StockIssueAllocation::class, 'stock_issue_id');
    }
}

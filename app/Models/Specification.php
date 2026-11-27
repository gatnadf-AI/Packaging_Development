<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Specification\SpecificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TSD-PDS-003 §3.3.3 — one version of one specification (SBK).
 *
 * A row here is a version, not a document: FSD-406 makes a revision "same
 * number, version suffix", so uq(number, version) lets the history grow one row
 * at a time and every earlier row stays readable. That is why nothing in this
 * class pretends a specification has one current row — the register asks for
 * the highest version per number, which is a query, not a column.
 *
 * doc_status is the document's own state and never the engine's: FSD-401 derives
 * approval_status from the ordered step rows and forbids writing it, so the two
 * vocabularies live in different tables. The five values are the CHECK
 * constraint's and SpecificationStatus's together (TSD §3.1).
 *
 * approval_document_id is absent from this schema and so from this class. The
 * workflow table it points at arrives in Phase 3; a pointer to it would be a
 * column nothing can fill until then.
 */
class Specification extends Model
{
    protected $fillable = [
        'number',
        'version',
        'item_id',
        'product_id',
        'material_name',
        'material_type_id',
        'effective_date',
        'doc_status',
        'author_id',
        'revision_of_id',
        'change_note',
    ];

    protected $casts = [
        'version' => 'integer',
        'doc_status' => SpecificationStatus::class,
        'effective_date' => 'date',
        'row_version' => 'integer',
    ];

    /**
     * @return BelongsTo<PackagingItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PackagingItem::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<MaterialType, $this>
     */
    public function materialType(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The four blocks §3.3.3 names, each ordered by the sort column the printed
     * form is laid out in (TSD §7.2).
     *
     * @return HasMany<SpecificationSize, $this>
     */
    public function sizes(): HasMany
    {
        return $this->hasMany(SpecificationSize::class)->orderBy('sort');
    }

    /**
     * @return HasMany<SpecificationParameter, $this>
     */
    public function parameters(): HasMany
    {
        return $this->hasMany(SpecificationParameter::class)->orderBy('sort');
    }

    /**
     * @return HasMany<SpecificationComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(SpecificationComponent::class)->orderBy('sort');
    }
}

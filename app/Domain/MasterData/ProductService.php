<?php

declare(strict_types=1);

namespace App\Domain\MasterData;

use App\Models\Product;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditService;
use App\Support\Persistence\RowVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * FSD-409 and FSD-812 — the product master.
 *
 * A product is the name a specification is written under, not the pack it is
 * written on: TSD §3.3.2 gives the specification both an item_id and a
 * product_id precisely because the same brand moves between packs, and FSD-409
 * states the consequence — a product may reference many specifications over
 * time. That asymmetry is why this list is kept as words rather than as
 * structure: code, name, brand, variant, pack size. Nothing here is a foreign
 * key, because a product is the subject a record is written about rather than a
 * step in one.
 *
 * There is no delete method, and the reason is not the one SCR-4.2 has. FSD-402
 * forbids deleting an item outright; FSD-812 never mentions deleting a product,
 * only that it "cannot be deactivated while referenced by an open assignment" —
 * so the rule is about a retirement gate rather than about removal, and G-13's
 * "deletion is not, once referenced" cannot be computed here at all while no
 * table can name a product. Both readings land on the same screen behaviour:
 * this list offers retirement and offers nothing more destructive, which is why
 * ProductScreenTest asserts no DELETE verb exists under master/products.
 *
 * The blank-versus-absent distinction is decided here rather than in a form.
 * brand, variant and pack size are nullable columns because the client's own
 * records leave them out, and two ways of saying "not recorded" — a null and a
 * two-space string — are one too many: a later reader comparing a variant
 * against nothing would be comparing it against a space. A field the caller
 * spoke about is written, an empty answer to it means null, and a field the
 * caller did not send keeps whatever it held.
 */
final class ProductService
{
    public const FEATURE = 'master.product';

    private const EDITABLE = ['code', 'name', 'brand', 'variant', 'pack_size', 'is_active'];

    private const OPTIONAL = ['brand', 'variant', 'pack_size'];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{code: string, name: string, brand?: string|null, variant?: string|null, pack_size?: string|null}  $input
     */
    public function create(array $input, ?Authenticatable $actor): Product
    {
        return DB::transaction(function () use ($input, $actor): Product {
            $product = Product::query()->create([
                'code' => trim((string) $input['code']),
                'name' => trim((string) $input['name']),
                'brand' => self::blankToNull($input['brand'] ?? null),
                'variant' => self::blankToNull($input['variant'] ?? null),
                'pack_size' => self::blankToNull($input['pack_size'] ?? null),
                'is_active' => true,
            ]);

            $this->audit->record(
                $actor,
                AuditAction::ProductCreated,
                $product,
                null,
                AuditService::snapshot($product, self::EDITABLE),
            );

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes, ?Authenticatable $actor, int $expectedVersion): Product
    {
        $next = $this->normalise($attributes, $product);

        if ($this->unchanged($product, $next)) {
            return $product;
        }

        $before = AuditService::snapshot($product, self::EDITABLE);

        $fresh = DB::transaction(fn (): Product => RowVersion::save($product, $expectedVersion, $next));

        $this->audit->record(
            $actor,
            AuditAction::ProductUpdated,
            $product,
            $before,
            AuditService::snapshot($fresh, self::EDITABLE),
        );

        return $fresh;
    }

    public function setActive(Product $product, bool $isActive, ?Authenticatable $actor, int $expectedVersion): Product
    {
        if ($product->is_active === $isActive) {
            return $product;
        }

        $before = AuditService::snapshot($product, self::EDITABLE);

        $fresh = DB::transaction(
            fn (): Product => RowVersion::save($product, $expectedVersion, ['is_active' => $isActive])
        );

        $this->audit->record(
            $actor,
            $isActive ? AuditAction::ProductUpdated : AuditAction::ProductDeactivated,
            $product,
            $before,
            AuditService::snapshot($fresh, self::EDITABLE),
        );

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalise(array $attributes, Product $product): array
    {
        $next = [
            'code' => trim((string) $attributes['code']),
            'name' => trim((string) $attributes['name']),
        ];

        foreach (self::OPTIONAL as $column) {
            $next[$column] = array_key_exists($column, $attributes)
                ? self::blankToNull($attributes[$column])
                : $product->{$column};
        }

        return $next;
    }

    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $next
     */
    private function unchanged(Product $product, array $next): bool
    {
        foreach ($next as $column => $value) {
            $stored = $product->{$column};

            if ($stored === $value || (string) $stored === (string) $value) {
                continue;
            }

            // Both sides of an unrecorded brand are the same fact, and a form's
            // empty box is that fact said a third way.
            if (($stored === null || $stored === '') && ($value === null || $value === '')) {
                continue;
            }

            return false;
        }

        return true;
    }
}

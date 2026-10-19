<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Enums\SkinType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The storefront's product listing: typo-tolerant search plus filters.
 *
 * Search uses pg_trgm word similarity on accent-free, lower-case text, one
 * query word at a time: every word must resemble a word of the product or
 * brand name ("hidratnte" finds "Hidratante"). Words shorter than three
 * letters match as prefixes instead, where trigrams are too noisy.
 */
final readonly class ProductSearch
{
    public const array SORTS = ['relevance', 'newest', 'price_asc', 'price_desc', 'name'];

    /**
     * @param  list<string>  $brands  brand slugs
     */
    public function __construct(
        public ?string $query = null,
        public ?string $category = null,
        public array $brands = [],
        public ?SkinType $skinType = null,
        public bool $veganOnly = false,
        public bool $crueltyFreeOnly = false,
        public bool $inStockOnly = false,
        public ?int $minPriceCents = null,
        public ?int $maxPriceCents = null,
        public string $sort = 'newest',
        public int $perPage = 24,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(): LengthAwarePaginator
    {
        $query = Product::query()
            ->visible()
            ->select('products.*')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->whereHas('variants', fn (Builder $q) => $q->where('is_active', true))
            ->withMin(['variants as from_price_cents' => fn (Builder $q) => $q->where('is_active', true)], 'price_cents')
            ->with(['brand', 'variants' => fn ($q) => $q->where('is_active', true)->with('stock')]);

        $this->applySearch($query);
        $this->applyFilters($query);
        $this->applySort($query);

        return $query->paginate($this->perPage)->withQueryString();
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySearch(Builder $query): void
    {
        $words = $this->words();
        if ($words === []) {
            return;
        }

        $rank = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3) {
                $query->where(fn (Builder $q) => $q
                    ->whereRaw("products.search_text ~ ('\\m' || search_normalize(?))", [$word])
                    ->orWhereRaw("brands.search_text ~ ('\\m' || search_normalize(?))", [$word]));

                continue;
            }

            $query->where(fn (Builder $q) => $q
                ->whereRaw('search_normalize(?) <% products.search_text', [$word])
                ->orWhereRaw('search_normalize(?) <% brands.search_text', [$word]));
            $rank[] = $word;
        }

        if ($rank !== [] && $this->sort === 'relevance') {
            $expression = implode(' + ', array_fill(0, count($rank), 'greatest(word_similarity(search_normalize(?), products.search_text), word_similarity(search_normalize(?), brands.search_text))'));
            $query->orderByRaw("({$expression}) DESC", array_merge(...array_map(fn (string $w): array => [$w, $w], $rank)));
        }
    }

    /**
     * Up to six query words. PostgreSQL normalises them (search_normalize)
     * exactly like the indexed text.
     *
     * @return list<string>
     */
    private function words(): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($this->query ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_unique($words), 0, 6);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyFilters(Builder $query): void
    {
        if ($this->category !== null) {
            // The category itself or any of its sub-categories.
            $query->whereIn('products.category_id', fn (QueryBuilder $q) => $q->select('id')->from('categories')
                ->where('slug', $this->category)
                ->orWhereIn('parent_id', fn (QueryBuilder $p) => $p->select('id')->from('categories')->where('slug', $this->category)));
        }

        if ($this->brands !== []) {
            $query->whereIn('brands.slug', $this->brands);
        }

        if ($this->skinType !== null) {
            $query->whereRaw('? = ANY (products.skin_types)', [$this->skinType->value]);
        }

        if ($this->veganOnly) {
            $query->where('products.is_vegan', true);
        }

        if ($this->crueltyFreeOnly) {
            $query->where('products.is_cruelty_free', true);
        }

        if ($this->inStockOnly) {
            $query->whereHas('variants', fn (Builder $q) => $q
                ->where('is_active', true)
                ->whereHas('stock', fn (Builder $s) => $s->where('available', '>', 0)));
        }

        if ($this->minPriceCents !== null || $this->maxPriceCents !== null) {
            $query->whereHas('variants', fn (Builder $q) => $q
                ->where('is_active', true)
                ->when($this->minPriceCents !== null, fn ($v) => $v->where('price_cents', '>=', $this->minPriceCents))
                ->when($this->maxPriceCents !== null, fn ($v) => $v->where('price_cents', '<=', $this->maxPriceCents)));
        }
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query): void
    {
        match ($this->sort) {
            'price_asc' => $query->orderBy('from_price_cents'),
            'price_desc' => $query->orderByDesc('from_price_cents'),
            'name' => $query->orderBy('products.name'),
            default => null,
        };

        // Ties (and "newest", and relevance after its score) by recency;
        // the id keeps pages stable.
        $query->orderByDesc('products.created_at')->orderByDesc('products.id');
    }
}

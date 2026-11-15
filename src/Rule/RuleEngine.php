<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Rule;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Ocena reguł bloku.
 *
 * Reguły włączające łączone wg logiki bloku (`and`: wszystkie, `or`:
 * dowolna). Każda dopasowana reguła wykluczająca ukrywa blok. Brak reguł
 * włączających znaczy „pokazuj”. Reguła katalogowa bez produktu i bez listingu
 * nie jest dopasowana.
 */
final class RuleEngine
{
    public function __construct(private Facts $facts)
    {
    }

    /**
     * @param array<int,array{type:string,mode:string,value:array<string,mixed>}> $rules
     */
    public function passes(array $rules, string $logic = 'and'): bool
    {
        $includes = 0;
        $matchedIncludes = 0;

        foreach ($rules as $rule) {
            $isExclude = ($rule['mode'] ?? 'include') === 'exclude';

            // Wykluczenia najpierw: jedno trafione kończy ocenę.
            if ($isExclude) {
                if ($this->matches($rule)) {
                    return false;
                }

                continue;
            }

            ++$includes;
        }

        if ($includes === 0) {
            return true;
        }

        foreach ($rules as $rule) {
            if (($rule['mode'] ?? 'include') === 'exclude') {
                continue;
            }

            $hit = $this->matches($rule);

            if ($logic === 'or' && $hit) {
                return true;
            }

            if ($logic !== 'or' && !$hit) {
                return false;
            }

            $matchedIncludes += $hit ? 1 : 0;
        }

        return $logic === 'or' ? $matchedIncludes > 0 : true;
    }

    /**
     * Wynik każdej reguły osobno, dla testera w panelu.
     *
     * @param array<int,array{type:string,mode:string,value:array<string,mixed>}> $rules
     *
     * @return array{passes:bool,rules:array<int,array{type:string,mode:string,matched:bool}>}
     */
    public function explain(array $rules, string $logic = 'and'): array
    {
        $out = [];

        foreach ($rules as $rule) {
            $out[] = [
                'type' => (string) ($rule['type'] ?? ''),
                'mode' => ($rule['mode'] ?? 'include') === 'exclude' ? 'exclude' : 'include',
                'matched' => $this->matches($rule),
            ];
        }

        return ['passes' => $this->passes($rules, $logic), 'rules' => $out];
    }

    /**
     * @param array{type:string,mode?:string,value:array<string,mixed>} $rule
     */
    public function matches(array $rule): bool
    {
        $v = (array) ($rule['value'] ?? []);
        $f = $this->facts;

        switch ((string) ($rule['type'] ?? '')) {
            case 'category':
                $ids = array_map('intval', (array) ($v['ids'] ?? []));
                $children = !empty($v['children']);

                if ($f->hasProduct()) {
                    $cats = $f->productCategories();

                    return (bool) array_intersect($ids, $children ? $cats['tree'] : $cats['direct']);
                }

                $tree = $f->listingCategoryTree();

                if ($tree === []) {
                    return false;
                }

                return $children ? (bool) array_intersect($ids, $tree) : in_array($f->page->idCategory, $ids, true);

            case 'manufacturer':
                $id = $f->manufacturerId();

                return $id > 0 && in_array($id, array_map('intval', (array) ($v['ids'] ?? [])), true);

            case 'supplier':
                $id = $f->supplierId();

                return $id > 0 && in_array($id, array_map('intval', (array) ($v['ids'] ?? [])), true);

            case 'feature':
                $feature = (int) ($v['id_feature'] ?? 0);
                $value = isset($v['id_value']) ? (int) $v['id_value'] : 0;

                foreach ($f->features() as [$idFeature, $idValue]) {
                    if ($idFeature === $feature && ($value <= 0 || $idValue === $value)) {
                        return true;
                    }
                }

                return false;

            case 'tag':
                return (bool) array_intersect(array_map('mb_strtolower', (array) ($v['tags'] ?? [])), $f->tags());

            case 'customer_group':
                return (bool) array_intersect(array_map('intval', (array) ($v['ids'] ?? [])), $f->groups());

            case 'country':
                return in_array($f->countryId(), array_map('intval', (array) ($v['ids'] ?? [])), true);

            case 'cart_value':
                $total = round($f->cartTotal(), 2);
                $min = $v['min'] ?? null;
                $max = $v['max'] ?? null;

                return ($min === null || $total >= round((float) $min, 2))
                    && ($max === null || $total <= round((float) $max, 2));

            case 'cart_product':
                return (bool) array_intersect(array_map('intval', (array) ($v['ids'] ?? [])), $f->cartProductIds());

            case 'currency':
                return in_array($f->currencyId(), array_map('intval', (array) ($v['ids'] ?? [])), true);

            case 'utm':
                $actual = $f->utm((string) ($v['param'] ?? ''));

                return $actual !== '' && mb_strtolower($actual) === mb_strtolower((string) ($v['value'] ?? ''));

            case 'search':
                $phrase = (string) ($v['phrase'] ?? '');

                return $phrase !== '' && $f->page->search !== '' && mb_stripos($f->page->search, $phrase) !== false;

            case 'device':
                return in_array($f->device(), (array) ($v['types'] ?? []), true);

            case 'customer':
                return ($v['state'] ?? '') === ($f->isLogged() ? 'logged' : 'guest');

            case 'language':
                return in_array($f->languageId(), array_map('intval', (array) ($v['ids'] ?? [])), true);

            case 'page':
                return in_array($f->page->page, (array) ($v['types'] ?? []), true);

            case 'url':
                $needle = (string) ($v['contains'] ?? '');

                return $needle !== '' && mb_stripos(rawurldecode($f->page->url), $needle) !== false;
        }

        return false;
    }
}

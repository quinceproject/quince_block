{**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 *
 * Otoczka bloku. Atrybuty data-qb-* czyta views/js/front.js. Ich wartości
 * zawierają tylko litery, cyfry, kropkę, myślnik i podkreślnik (sprawdzane
 * w FrontRenderer), treść bloku to HTML sprzedawcy wypisywany bez zmian.
 *}
<div class="quince-block" data-qb-id="{$qb.id|intval}"{if $qb.hook} data-qb-hook="{$qb.hook}"{/if}{if $qb.ctx} data-qb-ctx="{$qb.ctx}"{/if}{if $qb.refresh} data-qb-refresh="1"{/if}{if $qb.lazy} data-qb-lazy="1"{/if} data-qb-variant="{$qb.variant}"{if $qb.track} data-qb-token="{$qb.track}"{/if}{if $qb.random} data-qb-random="1"{/if}{if $qb.hidden} hidden{/if}>{if $qb.lazy}<div class="quince-block__placeholder" aria-hidden="true"></div>{else}{$qb.content nofilter}{/if}</div>

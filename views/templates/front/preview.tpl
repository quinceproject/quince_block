{**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 *
 * Podgląd bloku po prywatnym linku. Kod head i stopki bloku wypisujemy tutaj,
 * bo blok nieaktywny nie przechodzi przez displayHeader.
 *}
{extends file='page.tpl'}

{block name='page_title'}{/block}

{block name='page_content'}
  <div class="quince-block-preview">
    <p class="quince-block-preview__notice" role="status">
      {l s='Preview of the block "%title%". Visitors do not see this page.' sprintf=['%title%' => $qb_preview.title] d='Modules.Quinceblock.Shop'}
      {if $qb_preview.has_b}
        <a href="{$qb_preview.url_a}">{l s='Variant A' d='Modules.Quinceblock.Shop'}</a>
        <a href="{$qb_preview.url_b}">{l s='Variant B' d='Modules.Quinceblock.Shop'}</a>
      {/if}
    </p>
    {$qb_preview.head_code nofilter}
    {$qb_preview.html nofilter}
    {$qb_preview.footer_code nofilter}
  </div>
{/block}

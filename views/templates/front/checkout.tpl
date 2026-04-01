{**
 * QuickCheckout — Layout una columna
 * 1. Tabla de productos
 * 2. Totales
 * 3. Dirección
 * 4. Botón
 *}

{extends file='page.tpl'}

{block name='page_title'}
  {l s='Finalizar pedido' mod='quickcheckout'}
{/block}

{block name='page_content'}
<div class="qc-wrap">

  {* =====================================================
     1 — TABLA DE PRODUCTOS
  ===================================================== *}
  <div class="qc-card qc-card--table">
    <table class="qc-table">
      <thead>
        <tr>
          <th class="qc-th qc-th--product">{l s='Producto' mod='quickcheckout'}</th>
          <th class="qc-th qc-th--ref">{l s='Ref.' mod='quickcheckout'}</th>
          <th class="qc-th qc-th--price">{l s='Precio ud.' mod='quickcheckout'}</th>
          <th class="qc-th qc-th--qty">{l s='Cantidad' mod='quickcheckout'}</th>
          <th class="qc-th qc-th--total">{l s='Total' mod='quickcheckout'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $qc_products as $product}
          <tr class="qc-tr">
            <td class="qc-td qc-td--product">
              <div class="qc-product-cell">
                <a href="{$product.product_url|escape:'htmlall':'UTF-8'}" class="qc-product-img-wrap">
                  {if $product.cover}
                    <img src="{$product.cover|escape:'htmlall':'UTF-8'}" alt="{$product.name|escape:'htmlall':'UTF-8'}" class="qc-product-img" loading="lazy">
                  {else}
                    <span class="qc-product-img-empty">📦</span>
                  {/if}
                </a>
                <div class="qc-product-meta">
                  <a href="{$product.product_url|escape:'htmlall':'UTF-8'}" class="qc-product-name">{$product.name|escape:'htmlall':'UTF-8'}</a>
                  {if $product.attributes}<span class="qc-product-attr">{$product.attributes|escape:'htmlall':'UTF-8'}</span>{/if}
                </div>
              </div>
            </td>
            <td class="qc-td qc-td--ref"><span class="qc-ref">{$product.reference|escape:'htmlall':'UTF-8'}</span></td>
            <td class="qc-td qc-td--price">{$product.unit_price|escape:'htmlall':'UTF-8'}</td>
            <td class="qc-td qc-td--qty"><span class="qc-qty-badge">{$product.quantity|intval}</span></td>
            <td class="qc-td qc-td--total">{$product.total|escape:'htmlall':'UTF-8'}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>

  {* =====================================================
     2 — TOTALES
  ===================================================== *}
  <div class="qc-card qc-card--totals">
    <div class="qc-totals">
      <div class="qc-totals__row">
        <span>{l s='Subtotal' mod='quickcheckout'}</span>
        <span>{$qc_subtotal|escape:'htmlall':'UTF-8'}</span>
      </div>
      <div class="qc-totals__row">
        <span>{l s='Envío' mod='quickcheckout'}</span>
        <span>
          {if $qc_shipping_free}
            <strong class="qc-free">{l s='Gratis' mod='quickcheckout'}</strong>
          {else}
            {$qc_shipping|escape:'htmlall':'UTF-8'}
          {/if}
        </span>
      </div>
      <div class="qc-totals__row qc-totals__row--total">
        <span>{l s='Total (impuestos inc.)' mod='quickcheckout'}</span>
        <span>{$qc_total|escape:'htmlall':'UTF-8'}</span>
      </div>
    </div>
  </div>

  {* =====================================================
     3 — DIRECCIÓN
  ===================================================== *}
  <div class="qc-card" id="qc-address-block">
    <h2 class="qc-card__title">
      <svg class="qc-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
      {l s='Dirección de envío' mod='quickcheckout'}
    </h2>

    {if $qc_address_count == 0}

      <div class="qc-notice qc-notice--info">
        <p>{l s='No tienes ninguna dirección de envío guardada.' mod='quickcheckout'}</p>
      </div>
      <a href="{$qc_add_address_url|escape:'htmlall':'UTF-8'}" class="qc-btn qc-btn--outline">
        + {l s='Añadir dirección de envío' mod='quickcheckout'}
      </a>

    {elseif $qc_address_count == 1}

      <input type="hidden" id="qc-address-id" value="{$qc_addresses[0].id|intval}">
      <div class="qc-address-single">
        <div class="qc-address-single__name">{$qc_addresses[0].name|escape:'htmlall':'UTF-8'}</div>
        <div class="qc-address-single__line">{$qc_addresses[0].line1|escape:'htmlall':'UTF-8'}</div>
        <div class="qc-address-single__line">{$qc_addresses[0].line2|escape:'htmlall':'UTF-8'}</div>
      </div>
      <a href="{$qc_add_address_url|escape:'htmlall':'UTF-8'}" class="qc-link qc-link--small">
        {l s='Usar otra dirección' mod='quickcheckout'}
      </a>

    {else}

      <div class="qc-address-grid">
        {foreach $qc_addresses as $addr}
          <div
            class="qc-address-card {if $addr.id == $qc_selected_addr}qc-address-card--active{/if}"
            data-id="{$addr.id|intval}"
            onclick="qcSelectAddress(this, {$addr.id|intval})"
          >
            <div class="qc-address-card__dot"></div>
            <div class="qc-address-card__body">
              <span class="qc-address-card__alias">{$addr.alias|escape:'htmlall':'UTF-8'}</span>
              <span class="qc-address-card__name">{$addr.name|escape:'htmlall':'UTF-8'}</span>
              <span class="qc-address-card__line">{$addr.line1|escape:'htmlall':'UTF-8'}</span>
              <span class="qc-address-card__line">{$addr.line2|escape:'htmlall':'UTF-8'}</span>
            </div>
          </div>
        {/foreach}
      </div>
      <input type="hidden" id="qc-address-id" value="{$qc_selected_addr|intval}">
      <a href="{$qc_add_address_url|escape:'htmlall':'UTF-8'}" class="qc-link qc-link--small">
        + {l s='Añadir nueva dirección' mod='quickcheckout'}
      </a>

    {/if}
  </div>

  {* =====================================================
     4 — ERRORES + BOTÓN
  ===================================================== *}

  {if $qc_block_error}
    <div class="qc-notice qc-notice--error">
      <svg class="qc-notice__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div><strong>{l s='No podemos procesar tu pedido' mod='quickcheckout'}</strong><p>{$qc_block_error|escape:'htmlall':'UTF-8'}</p></div>
    </div>
  {/if}

  <div id="qc-ajax-error" class="qc-notice qc-notice--error" style="display:none;">
    <svg class="qc-notice__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div><strong>{l s='Error' mod='quickcheckout'}</strong><p id="qc-ajax-error-msg"></p></div>
  </div>

  <div class="qc-actions">
    <button
      id="qc-confirm-btn"
      class="qc-btn qc-btn--primary qc-btn--full"
      {if !$qc_can_order || $qc_address_count == 0}disabled{/if}
      data-process-url="{$qc_process_url|escape:'htmlall':'UTF-8'}"
      data-token="{$qc_token|escape:'htmlall':'UTF-8'}"
    >
      {l s='Completar pedido' mod='quickcheckout'}
    </button>
    <a href="{$qc_cart_url|escape:'htmlall':'UTF-8'}" class="qc-link qc-link--back">
      ← {l s='Volver al carrito' mod='quickcheckout'}
    </a>
  </div>

</div>
{/block}

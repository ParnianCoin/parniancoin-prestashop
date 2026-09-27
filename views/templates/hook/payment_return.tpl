<section class="parnianpay-return">
  <p class="alert {if $parnianpay_ok}alert-success{else}alert-info{/if}">
    {$parnianpay_message|escape:'html':'UTF-8'} <b dir="ltr">({$parnianpay_amount|escape:'html':'UTF-8'} PARC)</b>
  </p>
  {if $parnianpay_pay_url}
    <p><a class="btn btn-primary" href="{$parnianpay_pay_url|escape:'html':'UTF-8'}">{$parnianpay_open_pay|escape:'html':'UTF-8'}</a></p>
  {/if}
</section>

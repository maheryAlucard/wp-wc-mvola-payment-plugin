var successCallback = function (data) {
    $('form.woocommerce-checkout').find('#mvola_token').val(data.token);
    $('form.woocommerce-checkout').off('checkout_place_order', MVPaymentRequest);
    $('form.woocommerce-checkout').submit();
}

var errorCallback = function (data) {
    console.log(data);
}

var MVPaymentRequest = function(){
    // payment proccessing

    return false;
}

jQuery(function($){
    $('form.woocommerce-checkout').on('checkout_place_order', MVPaymentRequest)
});
<?php

namespace Azuriom\Plugin\AliPayment\Providers;

use Azuriom\Extensions\Plugin\BasePluginServiceProvider;
use Azuriom\Plugin\AliPayment\AliPayMethod;

class AliPaymentServiceProvider extends BasePluginServiceProvider
{
    /**
     * Register any plugin services.
     *
     * @return void
     */
    public function register(): void
    {
        // $this->registerMiddleware();

        //
    }

    /**
     * Bootstrap any plugin services.
     *
     * @return void
     */
    public function boot(): void
    {
        if (!plugins()->isEnabled('shop')) {
            logger()->warning('This plugin need the shop plugin to work !');

            return;
        }


	    $this->loadViews();
	    payment_manager()->registerPaymentMethod('alipay-business', AliPayMethod::class);
    }

}

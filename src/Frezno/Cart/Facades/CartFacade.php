<?php
namespace Frezno\Cart\Facades;

use Illuminate\Support\Facades\Facade;

class CartFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'cart';
    }
}

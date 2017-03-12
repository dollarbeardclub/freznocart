<?php
namespace Frezno\Cart;

use Illuminate\Support\Collection;

class ItemAttributeCollection extends Collection
{
    /**
     * Dynamically access item attribute name.
     *
     * @param string $name
     *
     * @return mixed
     */
    public function __get($name)
    {
        if ($this->has($name)) {
            return $this->get($name);
        }
    }
}

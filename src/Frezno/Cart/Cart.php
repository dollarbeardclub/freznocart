<?php
namespace Frezno\Cart;

use Frezno\Cart\Helpers\Helpers;
use Frezno\Cart\Validators\CartItemValidator;
use Frezno\Cart\Exceptions\InvalidItemException;
use Frezno\Cart\Exceptions\InvalidConditionException;

class Cart
{
    /**
     * The item storage.
     *
     * @var
     */
    protected $session;

    /**
     * The event dispatcher.
     *
     * @var
     */
    protected $events;

    /**
     * The cart session key.
     *
     * @var
     */
    protected $instanceName;

    /**
     * The session key use to persist cart items.
     *
     * @var
     */
    protected $sessionKeyCartItems;

    /**
     * The session key use to persist cart conditions.
     *
     * @var
     */
    protected $sessionKeyCartConditions;

    /**
     * Configuration to pass to ItemCollection.
     *
     * @var
     */
    protected $config;

    /**
     * Our object constructor.
     *
     * @param $session
     * @param $events
     * @param $instanceName
     * @param $session_key
     * @param $config
     */
    public function __construct($session, $events, $instanceName, $session_key, $config)
    {
        $this->events = $events;
        $this->session = $session;
        $this->instanceName = $instanceName;
        $this->sessionKeyCartItems = $session_key.'_cart_items';
        $this->sessionKeyCartConditions = $session_key.'_cart_conditions';
        $this->fireEvent('created');
        $this->config = $config;
    }

    /**
     * Get instance name of the cart.
     *
     * @return string
     */
    public function getInstanceName()
    {
        return $this->instanceName;
    }

    /**
     * Get an item on a cart by item ID.
     *
     * @param $itemId
     *
     * @return mixed
     */
    public function get($itemId)
    {
        return $this->getContent()->get($itemId);
    }

    /**
     * Check if an item exists by item ID.
     *
     * @param $itemId
     *
     * @return bool
     */
    public function has($itemId)
    {
        return $this->getContent()->has($itemId);
    }

    /**
     * Add item to the cart.
     *
     * It can be an array or a multi dimensional array.
     *
     * @param string|array        $id
     * @param string              $name
     * @param float               $price
     * @param int                 $quantity
     * @param array               $attributes
     * @param CartCondition|array $conditions
     *
     * @throws InvalidItemException
     *
     * @return $this
     */
    public function add($id, $name = null, $price = null, $quantity = null, $attributes = [], $conditions = [])
    {
        //-- If the first argument is an array,
        //-- we will need to call add again
        if (is_array($id)) {

            //-- The first argument is an array, now we will need to check
            //-- if it is a multi dimensional array.
            //-- If so, we will iterate through each item and call add again.
            if (Helpers::isMultiArray($id)) {
                foreach ($id as $item) {
                    $this->add(
                        $item['id'],
                        $item['name'],
                        $item['price'],
                        $item['quantity'],
                        Helpers::issetAndHasValueOrAssignDefault($item['attributes'], []),
                        Helpers::issetAndHasValueOrAssignDefault($item['conditions'], [])
                    );
                }
            } else {
                $this->add(
                    $id['id'],
                    $id['name'],
                    $id['price'],
                    $id['quantity'],
                    Helpers::issetAndHasValueOrAssignDefault($id['attributes'], []),
                    Helpers::issetAndHasValueOrAssignDefault($id['conditions'], [])
                );
            }

            return $this;
        }

        //-- Validate data.
        $item = $this->validate([
            'id' => $id,
            'name' => $name,
            'price' => Helpers::normalizePrice($price),
            'quantity' => $quantity,
            'attributes' => new ItemAttributeCollection($attributes),
            'conditions' => $conditions,
        ]);

        //-- Get the cart.
        $cart = $this->getContent();

        //-- If the item is already in the cart, we will just update it.
        if ($cart->has($id)) {
            $this->update($id, $item);
        } else {
            $this->addRow($id, $item);
        }

        return $this;
    }

    /**
     * Update a cart.
     *
     * The $data will be an associative array,
     * you don't need to pass all the data,
     * only the key value of the item you want to update on it.
     *
     * @param $id
     * @param $data
     *
     * @return bool
     */
    public function update($id, $data)
    {
        if ($this->fireEvent('updating', $data) === false) {
            return false;
        }

        $cart = $this->getContent();

        $item = $cart->pull($id);

        foreach ($data as $key => $value) {

            //-- If the key is currently "quantity" we will need to check
            //-- if an arithmetic symbol is present so we can decide
            //-- if the update of quantity is being added or being reduced.
            if ($key == 'quantity') {

                //-- We will check if quantity value provided is an array.
                //-- If it is, we will need to check if a key "relative" is set
                //-- and we will evaluate its value if true or false.
                //-- This tells us how to treat the quantity value,
                //-- ie if it should be updated relatively to its current quantity value
                //-- or if the value just be totally replaced.
                if (is_array($value)) {
                    if (isset($value['relative'])) {
                        if ((bool) $value['relative']) {
                            $item = $this->updateQuantityRelative($item, $key, $value['value']);
                        } else {
                            $item = $this->updateQuantityNotRelative($item, $key, $value['value']);
                        }
                    }
                } else {
                    $item = $this->updateQuantityRelative($item, $key, $value);
                }
            } elseif ($key == 'attributes') {
                $item[$key] = new ItemAttributeCollection($value);
            } else {
                $item[$key] = $value;
            }
        }

        $cart->put($id, $item);

        $this->save($cart);

        $this->fireEvent('updated', $item);

        return true;
    }

    /**
     * Add condition on an existing item on the cart.
     *
     * @param int|string    $productId
     * @param CartCondition $itemCondition
     *
     * @return $this
     */
    public function addItemCondition($productId, $itemCondition)
    {
        if ($product = $this->get($productId)) {
            $conditionInstance = '\\Frezno\\Cart\\CartCondition';

            if ($itemCondition instanceof $conditionInstance) {

                //-- We need to copy first to a temporary variable
                //-- to hold the conditions to avoid hitting this error:
                //-- "Indirect modification of overloaded element of Darryldecode\Cart\ItemCollection has no effect".
                //-- This is due to Laravel Collection instance that implements Array Access
                //--
                //-- Take a look at this link for more info:
                //-- http://stackoverflow.com/questions/20053269/indirect-modification-of-overloaded-element-of-splfixedarray-has-no-effect
                $itemConditionTempHolder = $product['conditions'];

                if (is_array($itemConditionTempHolder)) {
                    array_push($itemConditionTempHolder, $itemCondition);
                } else {
                    $itemConditionTempHolder = $itemCondition;
                }

                $this->update($productId, [
                    'conditions' => $itemConditionTempHolder, //-- the newly updated conditions
                ]);
            }
        }

        return $this;
    }

    /**
     * Removes an item on cart by item ID.
     *
     * @param int $id
     *
     * @return bool
     */
    public function remove($id)
    {
        $cart = $this->getContent();

        if ($this->fireEvent('removing', $id) === false) {
            return false;
        }

        $cart->forget($id);

        $this->save($cart);

        $this->fireEvent('removed', $id);

        return true;
    }

    /**
     * Clear cart.
     *
     * @return bool
     */
    public function clear()
    {
        if ($this->fireEvent('clearing') === false) {
            return false;
        }

        $this->session->put(
            $this->sessionKeyCartItems,
            []
        );

        $this->fireEvent('cleared');

        return true;
    }

    /**
     * Add a condition on the cart.
     *
     * @param CartCondition|array $condition
     *
     * @throws InvalidConditionException
     *
     * @return $this
     */
    public function condition($condition)
    {
        if (is_array($condition)) {
            foreach ($condition as $c) {
                $this->condition($c);
            }

            return $this;
        }

        if (!$condition instanceof CartCondition) {
            throw new InvalidConditionException('Argument 1 must be an instance of \'Frezno\Cart\CartCondition\'');
        }

        $conditions = $this->getConditions();

        //-- Check if order has been applied.
        if ($condition->getOrder() == 0) {
            $last = $conditions->last();
            $condition->setOrder(!is_null($last) ? $last->getOrder() + 1 : 1);
        }

        $conditions->put($condition->getName(), $condition);

        $conditions = $conditions->sortBy(function ($condition, $key) {
            return $condition->getOrder();
        });

        $this->saveConditions($conditions);

        return $this;
    }

    /**
     * Get conditions applied on the cart.
     *
     * @return CartConditionCollection
     */
    public function getConditions()
    {
        return new CartConditionCollection($this->session->get($this->sessionKeyCartConditions));
    }

    /**
     * Get condition applied on the cart by its name.
     *
     * @param $conditionName
     *
     * @return CartCondition
     */
    public function getCondition($conditionName)
    {
        return $this->getConditions()->get($conditionName);
    }

    /**
     * Get all the condition filtered by Type.
     *
     * Please Note that this will only return condition added on cart bases,
     * not those conditions added specifically on an per item basis.
     *
     * @param $type
     *
     * @return CartConditionCollection
     */
    public function getConditionsByType($type)
    {
        return $this->getConditions()->filter(function (CartCondition $condition) use ($type) {
            return $condition->getType() == $type;
        });
    }

    /**
     * Remove all the condition with the $type specified.
     *
     * Please Note that this will only remove condition added on cart bases,
     * not those conditions added specifically on an per item basis.
     *
     * @param $type
     *
     * @return $this
     */
    public function removeConditionsByType($type)
    {
        $this->getConditionsByType($type)->each(function ($condition) {
            $this->removeCartCondition($condition->getName());
        });
    }

    /**
     * Removes a condition on a cart by condition name.
     *
     * This can only remove conditions that are added on cart basis
     * and not conditions that are added on an item/product.
     * If you wish to remove a condition that has been added for a specific item/product,
     * you may use the removeItemCondition(itemId, conditionName) method instead.
     *
     * @param $conditionName
     */
    public function removeCartCondition($conditionName)
    {
        $conditions = $this->getConditions();

        $conditions->pull($conditionName);

        $this->saveConditions($conditions);
    }

    /**
     * Remove a condition that has been applied on an item
     * that is already on the cart.
     *
     * @param $itemId
     * @param $conditionName
     *
     * @return bool
     */
    public function removeItemCondition($itemId, $conditionName)
    {
        if (!$item = $this->getContent()->get($itemId)) {
            return false;
        }

        if ($this->itemHasConditions($item)) {

            //-- NOTE:
            //-- We do it this way:
            //-- We get first conditions and store it in a temp variable $originalConditions,
            //-- then we will modify the array there
            //-- and after modification we will store it again on $item['conditions'].
            //--
            //-- This is because of ArrayAccess implementation.
            //-- Take a look at this link for more info:
            //-- http://stackoverflow.com/questions/20053269/indirect-modification-of-overloaded-element-of-splfixedarray-has-no-effect
            $tempConditionsHolder = $item['conditions'];

            //-- If the item's conditions is in array format
            //-- we will iterate through all of it and check if the name matches
            //-- to the given name the user wants to remove.
            //-- If so, remove it.
            if (is_array($tempConditionsHolder)) {
                foreach ($tempConditionsHolder as $k => $condition) {
                    if ($condition->getName() == $conditionName) {
                        unset($tempConditionsHolder[$k]);
                    }
                }

                $item['conditions'] = $tempConditionsHolder;
            }

            //-- If the item condition is not an array,
            //-- we will check if it is an instance of a Condition.
            //-- If so, we will check if the name matches on the given
            //-- condition name the user wants to remove.
            //-- If so, lets just make $item['conditions'] an empty array
            //-- as there's just 1 condition on it anyway.
            else {
                $conditionInstance = 'Frezno\\Cart\\CartCondition';

                if ($item['conditions'] instanceof $conditionInstance) {
                    if ($tempConditionsHolder->getName() == $conditionName) {
                        $item['conditions'] = [];
                    }
                }
            }
        }

        $this->update($itemId, [
            'conditions' => $item['conditions'],
        ]);

        return true;
    }

    /**
     * Remove all conditions that has been applied
     * on an item that is already on the cart.
     *
     * @param $itemId
     *
     * @return bool
     */
    public function clearItemConditions($itemId)
    {
        if (!$item = $this->getContent()->get($itemId)) {
            return false;
        }

        $this->update($itemId, [
            'conditions' => [],
        ]);

        return true;
    }

    /**
     * Clears all conditions on a cart.
     *
     * This does not remove conditions
     * that has been added specifically to an item/product.
     * If you wish to remove a specific condition to a product,
     * you may use the method:
     * removeItemCondition($itemId, $conditionName).
     */
    public function clearCartConditions()
    {
        $this->session->put(
            $this->sessionKeyCartConditions,
            []
        );
    }

    /**
     * Get cart sub total.
     *
     * @param bool $formatted
     *
     * @return float
     */
    public function getSubTotal($formatted = true)
    {
        $cart = $this->getContent();

        $sum = $cart->sum(function ($item) {
            return $item->getPriceSumWithConditions(false);
        });

        return Helpers::formatValue(floatval($sum), $formatted, $this->config);
    }

    /**
     * The new total in which conditions are already applied.
     *
     * @return float
     */
    public function getTotal()
    {
        $subTotal = $this->getSubTotal(false);

        $newTotal = 0.00;

        $process = 0;

        $conditions = $this
            ->getConditions()
            ->filter(function ($cond) {
                return $cond->getTarget() === 'subtotal';
            });

        //-- If no conditions were added, just return the sub total.
        if (!$conditions->count()) {
            return Helpers::formatValue($subTotal, $this->config['format_numbers'], $this->config);
        }

        $conditions->each(function ($cond) use ($subTotal, &$newTotal, &$process) {
            $toBeCalculated = ($process > 0) ? $newTotal : $subTotal;

            $newTotal = $cond->applyCondition($toBeCalculated);

            ++$process;
        });

        return Helpers::formatValue($newTotal, $this->config['format_numbers'], $this->config);
    }

    /**
     * Get total quantity of items in the cart.
     *
     * @return int
     */
    public function getTotalQuantity()
    {
        $items = $this->getContent();

        if ($items->isEmpty()) {
            return 0;
        }

        $count = $items->sum(function ($item) {
            return $item['quantity'];
        });

        return $count;
    }

    /**
     * Get the cart.
     *
     * @return CartCollection
     */
    public function getContent()
    {
        return new CartCollection($this->session->get($this->sessionKeyCartItems));
    }

    /**
     * Check if cart is empty.
     *
     * @return bool
     */
    public function isEmpty()
    {
        $cart = new CartCollection($this->session->get($this->sessionKeyCartItems));

        return $cart->isEmpty();
    }

    /**
     * Validate item data.
     *
     * @param $item
     *
     * @throws InvalidItemException
     *
     * @return array $item;
     */
    protected function validate($item)
    {
        $rules = [
            'id' => 'required',
            'price' => 'required|numeric',
            'quantity' => 'required|numeric|min:1',
            'name' => 'required',
        ];

        $validator = CartItemValidator::make($item, $rules);

        if ($validator->fails()) {
            throw new InvalidItemException($validator->messages()->first());
        }

        return $item;
    }

    /**
     * Add row to cart collection.
     *
     * @param $id
     * @param $item
     *
     * @return bool
     */
    protected function addRow($id, $item)
    {
        if ($this->fireEvent('adding', $item) === false) {
            return false;
        }

        $cart = $this->getContent();

        $cart->put($id, new ItemCollection($item, $this->config));

        $this->save($cart);

        $this->fireEvent('added', $item);

        return true;
    }

    /**
     * Save the cart.
     *
     * @param $cart CartCollection
     */
    protected function save($cart)
    {
        $this->session->put($this->sessionKeyCartItems, $cart);
    }

    /**
     * Save the cart conditions.
     *
     * @param $conditions
     */
    protected function saveConditions($conditions)
    {
        $this->session->put($this->sessionKeyCartConditions, $conditions);
    }

    /**
     * Check if an item has condition.
     *
     * @param $item
     *
     * @return bool
     */
    protected function itemHasConditions($item)
    {
        if (!isset($item['conditions'])) {
            return false;
        }

        if (is_array($item['conditions'])) {
            return count($item['conditions']) > 0;
        }

        $conditionInstance = 'Frezno\\Cart\\CartCondition';

        if ($item['conditions'] instanceof $conditionInstance) {
            return true;
        }

        return false;
    }

    /**
     * Update a cart item quantity relative to its current quantity.
     *
     * @param $item
     * @param $key
     * @param $value
     *
     * @return mixed
     */
    protected function updateQuantityRelative($item, $key, $value)
    {
        if (preg_match('/\-/', $value) == 1) {
            $value = (int) str_replace('-', '', $value);

            //-- We will not allowed to reduced quantity to 0,
            //-- so if the given value would result to item quantity of 0,
            //-- we will not do it.
            if (($item[$key] - $value) > 0) {
                $item[$key] -= $value;
            }
        } elseif (preg_match('/\+/', $value) == 1) {
            $item[$key] += (int) str_replace('+', '', $value);
        } else {
            $item[$key] += (int) $value;
        }

        return $item;
    }

    /**
     * Update cart item quantity not relative to its current quantity value.
     *
     * @param $item
     * @param $key
     * @param $value
     *
     * @return mixed
     */
    protected function updateQuantityNotRelative($item, $key, $value)
    {
        $item[$key] = (int) $value;

        return $item;
    }

    /**
     * Setter for decimals. Change value on demand.
     *
     * @param $decimals
     */
    public function setDecimals($decimals)
    {
        $this->decimals = $decimals;
    }

    /**
     * Setter for decimals point. Change value on demand.
     *
     * @param $dec_point
     */
    public function setDecPoint($dec_point)
    {
        $this->dec_point = $dec_point;
    }

    /**
     * Setter for thousands seperator. Change value on demand.
     *
     * @param $thousands_sep
     */
    public function setThousandsSep($thousands_sep)
    {
        $this->thousands_sep = $thousands_sep;
    }

    /**
     * Fire an event.
     *
     * @param $name
     * @param $value
     *
     * @return mixed
     */
    protected function fireEvent($name, $value = [])
    {
        return $this->events->fire($this->getInstanceName().'.'.$name, array_values([$value, $this]));
    }
}

<?php

namespace Frezno\Cart;

use Frezno\Cart\Exceptions\InvalidConditionException;
use Frezno\Cart\Helpers\Helpers;
use Frezno\Cart\Validators\CartConditionValidator;

class CartCondition
{
    /**
     * @var array
     */
    private $args;

    /**
     * The parsed raw value of the condition
     *
     * @var
     */
    private $parsedRawValue;

    /**
     * @param array $args (name, type, target, value)
     * @throws InvalidConditionException
     */
    public function __construct(array $args)
    {
        $this->args = $args;

        if (Helpers::isMultiArray($args)) {
            Throw new InvalidConditionException('Multi dimensional array is not supported.');
        } else {
            $this->validate($this->args);
        }
    }

    /**
     * The target of where the condition is applied.
     *
     * @return mixed
     */
    public function getTarget()
    {
        return $this->args['target'];
    }

    /**
     * The name of the condition.
     *
     * @return mixed
     */
    public function getName()
    {
        return $this->args['name'];
    }

    /**
     * The type of the condition.
     *
     * @return mixed
     */
    public function getType()
    {
        return $this->args['type'];
    }

    /**
     * Get the additional attributes of a condition.
     *
     * @return array
     */
    public function getAttributes()
    {
        return (isset($this->args['attributes'])) ? $this->args['attributes'] : array();
    }

    /**
     * The value of this the condition.
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->args['value'];
    }

    /**
     * Set the order to apply this condition.
     * If no argument order is applied we return 0 as
     * indicator that no assignment has been made.
     *
     * @param int $order
     * @return Integer
     */
    public function setOrder($order = 1)
    {
        $this->args['order'] = $order;
    }

    /**
     * The order to apply this condition.
     * If no argument order is applied we return 0 as
     * indicator that no assignment has been made.
     *
     * @return Integer
     */
    public function getOrder()
    {
        return isset($this->args['order']) && is_numeric($this->args['order']) ? (int)$this->args['order'] : 0;
    }

    /**
     * Apply condition to total or subtotal.
     *
     * @param $totalOrSubTotalOrPrice
     * @return float
     */
    public function applyCondition($totalOrSubTotalOrPrice)
    {
        return $this->apply($totalOrSubTotalOrPrice, $this->getValue());
    }

    /**
     * Get the calculated value of this condition supplied by the subtotal|price.
     *
     * @param $totalOrSubTotalOrPrice
     * @return mixed
     */
    public function getCalculatedValue($totalOrSubTotalOrPrice)
    {
        $this->apply($totalOrSubTotalOrPrice, $this->getValue());

        return $this->parsedRawValue;
    }

    /**
     * Apply condition.
     *
     * @param $totalOrSubTotalOrPrice
     * @param $conditionValue
     * @return float
     */
    protected function apply($totalOrSubTotalOrPrice, $conditionValue)
    {
        // If value has a percentage sign on it, we will get first
        // its percentage, then we will evaluate again if the value
        // has a minus or plus sign so we can decide what to do with the
        // percentage, whether to add or subtract it to the total/subtotal/price.
        // If we can't find any plus/minus sign, we will assume it as plus sign.
        if( $this->valueIsPercentage($conditionValue) )
        {
            if ($this->valueIsToBeSubtracted($conditionValue)) {
                $value = Helpers::normalizePrice($this->cleanValue($conditionValue));

                $this->parsedRawValue = $totalOrSubTotalOrPrice * ($value / 100);

                $result = floatval($totalOrSubTotalOrPrice - $this->parsedRawValue);
            } elseif ($this->valueIsToBeAdded($conditionValue)) {
                $value = Helpers::normalizePrice($this->cleanValue($conditionValue));

                $this->parsedRawValue = $totalOrSubTotalOrPrice * ($value / 100);

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            } else {
                $value = Helpers::normalizePrice($conditionValue);

                $this->parsedRawValue = $totalOrSubTotalOrPrice * ($value / 100);

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
        }

        // If the value has no percent sign on it, the operation will not be a percentage.
        // Next is we will check if it has a minus/plus sign so then we can just deduct it to total/subtotal/price.
        else {
            if ($this->valueIsToBeSubtracted($conditionValue)) {
                $this->parsedRawValue = Helpers::normalizePrice($this->cleanValue($conditionValue));

                $result = floatval($totalOrSubTotalOrPrice - $this->parsedRawValue);
            } elseif ($this->valueIsToBeAdded($conditionValue)) {
                $this->parsedRawValue = Helpers::normalizePrice($this->cleanValue($conditionValue));

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            } else {
                $this->parsedRawValue = Helpers::normalizePrice($conditionValue);

                $result = floatval($totalOrSubTotalOrPrice + $this->parsedRawValue);
            }
        }

        // Do not allow items with negative prices.
        return $result < 0 ? 0.00 : $result;
    }

    /**
     * Check if value is a percentage.
     *
     * @param $value
     * @return bool
     */
    protected function valueIsPercentage($value)
    {
        return (preg_match('/%/', $value) == 1);
    }

    /**
     * Check if value is a subtract.
     *
     * @param $value
     * @return bool
     */
    protected function valueIsToBeSubtracted($value)
    {
        return (preg_match('/\-/', $value) == 1);
    }

    /**
     * Check if value is to be added.
     *
     * @param $value
     * @return bool
     */
    protected function valueIsToBeAdded($value)
    {
        return (preg_match('/\+/', $value) == 1);
    }

    /**
     * Removes some arithmetic signs (%,+,-) only.
     *
     * @param $value
     * @return mixed
     */
    protected function cleanValue($value)
    {
        return str_replace(array('%','-','+'),'',$value);
    }

    /**
     * Validates condition arguments.
     *
     * @param $args
     * @throws InvalidConditionException
     */
    protected function validate($args)
    {
        $rules = array(
            'name' => 'required',
            'type' => 'required',
            'target' => 'required',
            'value' => 'required',
        );

        $validator = CartConditionValidator::make($args, $rules);

        if ($validator->fails()) {
            throw new InvalidConditionException($validator->messages()->first());
        }
    }
}

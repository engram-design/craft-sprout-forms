<?php

namespace barrelstrength\sproutforms\rules\conditions;

use barrelstrength\sproutforms\base\Condition;
use Craft;

/**
 *
 * @property string $label
 */
class IsLessThanCondition extends Condition
{
    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'is less than';
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['inputValue'], 'validateCondition']
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateCondition()
    {
        if (!($this->inputValue < $this->ruleValue)) {
            $this->addError('inputValue', Craft::t('sprout-forms', 'Condition does not validate'));
        }
    }
}
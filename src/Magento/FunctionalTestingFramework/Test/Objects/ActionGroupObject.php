<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\FunctionalTestingFramework\Test\Objects;

use Magento\FunctionalTestingFramework\Exceptions\TestReferenceException;
use Magento\FunctionalTestingFramework\Test\Util\ActionGroupObjectExtractor;
use Magento\FunctionalTestingFramework\Test\Util\ActionMergeUtil;

/**
 * Class ActionGroupObject
 */
class ActionGroupObject
{
    const ACTION_GROUP_ORIGIN_NAME = "actionGroupName";
    const ACTION_GROUP_ORIGIN_TEST_REF = "testInvocationRef";

    /**
     * Array of variable-enabled attributes.
     * @var array
     */
    private $varAttributes;

    /**
     * The name of the action group
     *
     * @var string
     */
    private $name;

    /**
     * An array of action objects
     *
     * @var array
     */
    private $parsedActions = [];

    /**
     * An array used to store argument names to values
     *
     * @var array
     */
    private $arguments;

    /**
     * ActionGroupObject constructor.
     *
     * @param string $name
     * @param ArgumentObject[] $arguments
     * @param array $actions
     */
    public function __construct($name, $arguments, $actions)
    {
        $this->varAttributes = array_merge(
            ActionObject::SELECTOR_ENABLED_ATTRIBUTES,
            ActionObject::DATA_ENABLED_ATTRIBUTES
        );
        $this->varAttributes[] = ActionObject::ACTION_ATTRIBUTE_URL;
        $this->name = $name;
        $this->arguments = $arguments;
        $this->parsedActions = $actions;
    }

    /**
     * Gets the ordered steps including merged waits
     *
     * @param array $arguments
     * @param string $actionReferenceKey
     * @return array
     * @throws TestReferenceException
     */
    public function getSteps($arguments, $actionReferenceKey)
    {
        $mergeUtil = new ActionMergeUtil($this->name, "ActionGroup");

        $args = $this->resolveArguments($arguments);

        return $mergeUtil->resolveActionSteps($this->getResolvedActionsWithArgs($args, $actionReferenceKey), true);
    }

    /**
     * Iterates through given $arguments and overrides ActionGroup's argument values, if any are found.
     * @param array $arguments
     * @return ArgumentObject[]
     */
    private function resolveArguments($arguments)
    {
        $resolvedArgumentList = [];
        $emptyArguments = [];

        foreach ($this->arguments as $argumentObj) {
            if ($arguments !== null && array_key_exists($argumentObj->getName(), $arguments)) {
                $resolvedArgumentList[] = new ArgumentObject(
                    $argumentObj->getName(),
                    $arguments[$argumentObj->getName()],
                    $argumentObj->getDataType()
                );
            } elseif ($argumentObj->getValue() === null) {
                $emptyArguments[] = $argumentObj->getName();
            } else {
                $resolvedArgumentList[] = $argumentObj;
            }
        }

        if (!empty($emptyArguments)) {
            $error = 'Arguments missed (' . implode(", ", $emptyArguments) . ') for actionGroup "' . $this->name . '"';
            throw new TestReferenceException($error);
        }

        return $resolvedArgumentList;
    }

    /**
     * Function which takes a set of arguments to be appended to an action objects fields returns resulting
     * action objects with proper argument.field references.
     *
     * @param array $arguments
     * @param string $actionReferenceKey
     * @return array
     */
    private function getResolvedActionsWithArgs($arguments, $actionReferenceKey)
    {
        $resolvedActions = [];

        // $regexPattern match on:   $matches[0] {{section.element(arg.field)}}
        // $matches[1] = section.element
        // $matches[2] = arg.field
        $regexPattern = '/{{([\w.\[\]]+)\(*([\w.$\',\s\[\]]+)*\)*}}/';

        foreach ($this->parsedActions as $action) {
            $varAttributes = array_intersect($this->varAttributes, array_keys($action->getCustomActionAttributes()));
            $newActionAttributes = [];

            if (!empty($varAttributes)) {
                // 1 check to see if we have pertinent var
                foreach ($varAttributes as $varAttribute) {
                    $attributeValue = $action->getCustomActionAttributes()[$varAttribute];
                    preg_match_all($regexPattern, $attributeValue, $matches);
                    if (empty($matches[0])) {
                        continue;
                    }

                    //get rid of full match {{arg.field(arg.field)}}
                    array_shift($matches);

                    $newActionAttributes[$varAttribute] = $this->replaceAttributeArguments(
                        $arguments,
                        $attributeValue,
                        $matches
                    );
                }
            }

            // we append the action reference key to any linked action and the action's merge key as the user might
            // use this action group multiple times in the same test.
            $resolvedActions[$action->getStepKey() . ucfirst($actionReferenceKey)] = new ActionObject(
                $action->getStepKey() . ucfirst($actionReferenceKey),
                $action->getType(),
                array_merge($action->getCustomActionAttributes(), $newActionAttributes),
                $action->getLinkedAction() == null ? null : $action->getLinkedAction() . ucfirst($actionReferenceKey),
                $action->getOrderOffset(),
                [self::ACTION_GROUP_ORIGIN_NAME => $this->name,
                    self::ACTION_GROUP_ORIGIN_TEST_REF => $actionReferenceKey]
            );
        }

        return $resolvedActions;
    }

    /**
     * Function that takes an array of replacement arguments, and matches them with args in an actionGroup's attribute.
     * Determines if the replacement arguments are persisted data, and replaces them accordingly.
     *
     * @param array $arguments
     * @param string $attributeValue
     * @param array $matches
     * @return string
     */
    private function replaceAttributeArguments($arguments, $attributeValue, $matches)
    {
        list($mainValueList, $possibleArgumentsList) = $matches;

        foreach ($mainValueList as $index => $mainValue) {
            $possibleArguments = $possibleArgumentsList[$index];

            $attributeValue = $this->replaceAttributeArgumentInVariable($mainValue, $arguments, $attributeValue);

            // Split on commas, trim all values, and finally filter out all FALSE values
            $argumentList = array_filter(array_map('trim', explode(',', $possibleArguments)));

            foreach ($argumentList as $argumentValue) {
                $attributeValue = $this->replaceAttributeArgumentInVariable(
                    $argumentValue,
                    $arguments,
                    $attributeValue,
                    true
                );
            }
        }

        return $attributeValue;
    }

    /**
     * Replace attribute arguments in variable.
     *
     * @param string $variable
     * @param array $arguments
     * @param string $attributeValue
     * @param bool $isInnerArgument
     * @return string
     */
    private function replaceAttributeArgumentInVariable(
        $variable,
        $arguments,
        $attributeValue,
        $isInnerArgument = false
    ) {
        // Truncate arg.field into arg
        $variableName = strstr($variable, '.', true);
        // Check if arguments has a mapping for the given variableName

        if ($variableName === false) {
            $variableName = trim($variable, "'");
        }

        $matchedArgument = $this->findArgumentByName($variableName, $arguments);
        if ($matchedArgument === null) {
            return $attributeValue;
        }

        if ($matchedArgument->getDataType() === ArgumentObject::ARGUMENT_DATA_STRING) {
            return $this->replaceSimpleArgument(
                $matchedArgument->getResolvedValue($isInnerArgument),
                $variableName,
                $attributeValue,
                $isInnerArgument
            );
        }

        $isPersisted = preg_match('/\$[\w.\[\]() ]+\$/', $matchedArgument->getResolvedValue($isInnerArgument));
        if ($isPersisted) {
            return $this->replacePersistedArgument(
                $matchedArgument->getResolvedValue($isInnerArgument),
                $attributeValue,
                $variable,
                $variableName,
                $isInnerArgument
            );
        }

        //replace argument ONLY when there is no letters attached before after (ex. category.name vs categoryTreeButton)
        return preg_replace(
            "/(?<![\w]){$variableName}(?![(\w])/",
            $matchedArgument->getResolvedValue($isInnerArgument),
            $attributeValue
        );
    }

    /**
     * Replaces any arguments that were declared as simpleData="true".
     * Takes in isInnerArgument to determine what kind of replacement to expect: {{data}} vs section.element(data)
     * @param string $argumentValue
     * @param string $variableName
     * @param string $attributeValue
     * @param boolean $isInnerArgument
     * @return string
     */
    private function replaceSimpleArgument($argumentValue, $variableName, $attributeValue, $isInnerArgument)
    {
        if ($isInnerArgument) {
            return preg_replace("/(?<![\w]){$variableName}(?![(\w])/", $argumentValue, $attributeValue);
        } else {
            return str_replace("{{{$variableName}}}", $argumentValue, $attributeValue);
        }
    }

    /**
     * Replaces args with replacements given, behavior is specific to persisted arguments.
     * @param string $replacement
     * @param string $attributeValue
     * @param string $fullVariable
     * @param string $variable
     * @param boolean $isParameter
     * @return string
     */
    private function replacePersistedArgument($replacement, $attributeValue, $fullVariable, $variable, $isParameter)
    {
        //hookPersisted will be true if replacement passed in is $$arg.field$$, otherwise assume it's $arg.field$
        $hookPersistedArgumentRegex = '/\$\$[\w.\[\]\',]+\$\$/';
        $hookPersisted = (preg_match($hookPersistedArgumentRegex, $replacement));

        $newAttributeValue = $attributeValue;

        $scope = '$';
        if ($hookPersisted) {
            $scope = '$$';
        }

        // parameter replacements require changing of (arg.field) to ($arg.field$)
        if ($isParameter) {
            $fullReplacement = str_replace($variable, trim($replacement, '$'), trim($fullVariable, "'"));
            $newAttributeValue = str_replace($fullVariable, $scope . $fullReplacement . $scope, $newAttributeValue);
        } else {
            $newAttributeValue = str_replace('{{', $scope, str_replace('}}', $scope, $newAttributeValue));
            $newAttributeValue = str_replace($variable, trim($replacement, '$'), $newAttributeValue);
        }

        return $newAttributeValue;
    }

    /**
     * Finds and returns all original stepkeys of actions in actionGroup.
     * @return string[]
     */
    public function extractStepKeys()
    {
        $originalKeys = [];
        foreach ($this->parsedActions as $action) {
            $originalKeys[] = $action->getStepKey();
        }
        return $originalKeys;
    }

    /**
     * Searches through ActionGroupObject's arguments and returns first argument wi
     * @param string $name
     * @param array $argumentList
     * @return ArgumentObject|null
     */
    private function findArgumentByName($name, $argumentList)
    {
        $matchedArgument = array_filter(
            $argumentList,
            function ($e) use ($name) {
                return $e->getName() == $name;
            }
        );
        if (isset(array_values($matchedArgument)[0])) {
            return array_values($matchedArgument)[0];
        }
        return null;
    }
}

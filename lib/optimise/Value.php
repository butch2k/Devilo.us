<?php
/**
 * CSSTidy - CSS Parser and Optimiser
 *
 * CSS Optimising Class
 * This class optimises CSS data generated by csstidy.
 *
 * Copyright 2005, 2006, 2007 Florian Schmitz
 *
 * This file is part of CSSTidy.
 *
 *   CSSTidy is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU Lesser General Public License as published by
 *   the Free Software Foundation; either version 2.1 of the License, or
 *   (at your option) any later version.
 *
 *   CSSTidy is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Lesser General Public License for more details.
 * 
 *   You should have received a copy of the GNU Lesser General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @license http://opensource.org/licenses/lgpl-license.php GNU Lesser General Public License
 * @package CSSTidy
 * @author Florian Schmitz (floele at gmail dot com) 2005-2007
 * @author Brett Zamir (brettz9 at yahoo dot com) 2007
 * @author Nikolay Matsievsky (speed at webo dot name) 2009-2010
 * @author Jakub Onderka (acci at acci dot cz) 2011
 */
namespace CSSTidy\Optimise;

use CSSTidy\Parser;
use CSSTidy\Logger;
use CSSTidy\Configuration;
use CSSTidy\Element;

class Value
{
    /** @var \CSSTidy\Logger */
    protected $logger;

    /** @var \CSSTidy\Configuration */
    protected $configuration;

    /** @var \CSSTidy\Optimise\Color */
    protected $optimiseColor;

    /** @var \CSSTidy\Optimise\Number */
    protected $optimiseNumber;

    public function __construct(
        Logger $logger,
        Configuration $configuration,
        \CSSTidy\Optimise\Color $optimiseColor,
        \CSSTidy\Optimise\Number $optimiseNumber
    ) {
        $this->logger = $logger;
        $this->configuration = $configuration;
        $this->optimiseColor = $optimiseColor;
        $this->optimiseNumber = $optimiseNumber;
    }

    /**
     * @param \CSSTidy\Element\Block $block
     */
    public function process(Element\Block $block)
    {
        foreach ($block->elements as $element) {
            if ($element instanceof Element\Property) {
                $this->subValue($element);
                $this->value($element);
            } else if ($element instanceof Element\Block) {
                $this->process($element);
            }
        }
    }

    /**
     * Optimises a sub-value
     * @param \CSSTidy\Element\Property $property
     */
    public function subValue(Element\Property $property)
    {
        $compressFontWeight = $optimiseGradients = $removeQuotes = false;

        switch ($property->getName()) {
            case 'font-weight':
                $compressFontWeight = $this->configuration->getCompressFontWeight();
                break;

            case 'background-image':
                $optimiseGradients = $this->configuration->getCompressColors();
                break;
			
			/*
			* removed for the sake of compatibility.
            case 'font':
            case 'font-family':
                $removeQuotes = true;
                break;
			*/
        }

        foreach ($property->subValues as &$subValue) {

            if ($compressFontWeight) {
                $subValue = $this->compressFontWeight($subValue);
                continue;
            } else if ($optimiseGradients) {
                $subValue = $this->optimizeGradients($subValue);
            } else if ($removeQuotes) {
                $subValue = $this->removeQuotesFromFontFamily($subValue);
            }

            if (substr_compare($subValue, 'url(', 0, 4, true) === 0) {
                $subValue = "url(" . $this->removeQuotes(substr($subValue, 4, -1)) . ')';
                continue;
            }

            $subValue = $this->optimiseNumber->optimise($property->getName(), $subValue);

            if ($this->configuration->getCompressColors()) {
                $subValue = $this->optimiseColor->optimise($subValue);
            }

            $subValue = $this->optimizeCalc($subValue);
        }
    }

    /**
     * Compress value
     * @param \CSSTidy\Element\Property $property
     */
    public function value(Element\Property $property)
    {
        if ($this->removeVendorPrefix($property->getName()) === 'transform') {
            $property->setValue($this->optimizeTransform($property->getValueWithoutImportant()));
        }
    }

    /**
     * Change 'bold' to '700' and 'normal' to '400'
     * @see http://www.w3.org/TR/css3-fonts/#font-weight-prop
     * @param string $value
     * @return string
     */
    public function compressFontWeight($value)
    {
        static $optimizedFontWeight = array('bold' => 700, 'normal' => 400);

        if (isset($optimizedFontWeight[$value])) {
            return $optimizedFontWeight[$value];
        }

        return $value;
    }

    /**
     * @param string $fontFamily
     * @return string
     */
    protected function removeQuotesFromFontFamily($fontFamily)
    {
        if ($fontFamily{0} === '"' || $fontFamily{0} === "'") {

            if (ctype_space($fontFamily{1}) || ctype_space(substr($fontFamily, -2, 1))) {
                // If first or last character inside font-family string is whitespace
               // or first character is number, don't remove quotes
                return $fontFamily;
            } else if (preg_match('|[' . Parser::$whitespace .']{2}|uis', $fontFamily)) {
                // If string contains two or more consecutive whitespace character, don't remove quotes
                return $fontFamily;
            }

            $withoutQuotes = substr($fontFamily, 1, -1);
            $parts = explode(' ', $withoutQuotes);

            foreach ($parts as $part) {
                if (ctype_digit($part{0}) || !preg_match('~^[a-zA-Z0-9_-]+$~', $part)) {
                    return $fontFamily;
                }
            }

            // Remove quotes
            return $withoutQuotes;
        }

        return $fontFamily;
    }

    /**
     * @param string $string
     * @return string
     */
    protected function removeQuotes($string)
    {
        if ($string{0} === '"' || $string{0} === "'") {
            $withoutQuotes = substr($string, 1, -1);
            if (preg_match('|[' . Parser::$whitespace . '\'"()]|uis', $withoutQuotes)) { // If string contains whitespace
                return $string;
            }

            return $withoutQuotes;
        } else {
            return $string;
        }
    }

    /**
     * Compress color inside gradient definition
     * @param string $string
     * @return string
     */
    protected function optimizeGradients($string)
    {
        /*
         * Gradient functions and color start from
         * -webkit-gradient syntax is not supported, because is deprecated
         */
        static $supportedGradients = array(
            'repeating-linear-gradient' => 1,
            'linear-gradient' => 1,
            'repeating-radial-gradient' => 2,
            'radial-gradient' => 2,
        );

        if (!isset($string{16})) {
            return $string;
        }

        $originalType = strstr($string, '(', true);
        $type = $this->removeVendorPrefix($originalType);

        if ($type === false || !isset($supportedGradients[$type])) {
            return $string; // value is not gradient or unsupported type
        }

        $string = substr($string, strlen($originalType) + 1, -1); // Remove linear-gradient()
        $parts = Parser::explodeWithoutString(',', $string);

        $start = $supportedGradients[$type];
        foreach ($parts as $i => &$part) {
            if ($i < $start) {
                continue;
            }

            $colorAndLength = Parser::explodeWithoutString(' ', $part);
            $colorAndLength[0] = $this->optimiseColor->optimise($colorAndLength[0]);
            $part = implode(' ', $colorAndLength);
        }

        return "$originalType(" . implode(',', $parts) . ')';
    }

    /**
     * Optimize calc(), min(), max()
     *
     * @see http://www.w3.org/TR/css3-values/#calc
     * @param string $string
     * @return string
     */
    protected function optimizeCalc($string)
    {
        static $supportedTypes = array('min' => true, 'max' => true, 'calc' => true);

        $type = strstr($string, '(', true);

        if ($type === false || !isset($supportedTypes[$type])) {
            return $string;
        }

        $string = substr($string, strlen($type) + 1, -1); // Remove calc()
        $parts = Parser::explodeWithoutString(',', $string);

        foreach ($parts as &$part) {
            $part = str_replace(' ', '', $part);
        }

        return "$type(" . implode(',', $parts) . ')';
    }

    /**
     * @param $string
     * @return string
     */
    protected function optimizeTransform($string)
    {
        static $supportedTypes = array(
            'perspective' => true,
            'matrix' => true,
            'matrix3d' => true,
            'translate' => true,
            'translate3d' => true,
            'translateX' => true,
            'translateY' => true,
            'translateZ' => true,
            'scale3d' => true,
            'scaleX' => true,
            'scaleY' => true,
            'scaleZ' => true,
            'rotate3d' => true,
            'rotateX' => true,
            'rotateY' => true,
            'rotateZ' => true,
            'rotate' => true,
            'skewX' => true,
            'skewY' => true,
            'skew' => true,
        );

        $functions = Parser::explodeWithoutString(' ', $string);

        $output = array();
        foreach ($functions as $function) {
            $type = strstr($function, '(', true);

            if ($type === false || !isset($supportedTypes[$type])) {
                $output[] = $function;
                continue;
            }

            $function = substr($function, strlen($type) + 1, -1); // Remove function()
            $parts = Parser::explodeWithoutString(',', $function);

            foreach ($parts as &$part) {
                $part = $this->optimiseNumber->optimise(null, $part);
            }

            $output[$type] = implode(',', $parts);
        }

        // 3D transform
        foreach (array('scale', 'translate') as $mergeFunction) {
            if (isset($output[$mergeFunction . 'X']) && isset($output[$mergeFunction . 'Y']) && isset($output[$mergeFunction . 'Z'])) {
                $output[$mergeFunction . '3d'] = "{$output[$mergeFunction . 'X']},{$output[$mergeFunction . 'Y']},{$output[$mergeFunction . 'Z']}";
                unset($output[$mergeFunction . 'X'], $output[$mergeFunction . 'Y'], $output[$mergeFunction . 'Z']);
            }
        }

        // 2D transform
        foreach (array('skew', 'scale', 'translate', 'rotate') as $mergeFunction) {
            if (isset($output[$mergeFunction . 'X']) && isset($output[$mergeFunction . 'Y'])) {
                $output[$mergeFunction] = "{$output[$mergeFunction . 'X']},{$output[$mergeFunction . 'Y']}";
                unset($output[$mergeFunction . 'X'], $output[$mergeFunction . 'Y']);
            }
        }

        $outputString = '';
        foreach ($output as $name => $value) {
            if (is_numeric($name)) {
                $outputString .= $value . ' ';
            } else {
                $outputString .= "$name($value) ";
            }
        }

        return rtrim($outputString);
    }

    /**
     * Remove vendor prefix pro property. For example '-moz-transform' is changed to 'transform'
     * @param string $string
     * @return string
     */
    protected function removeVendorPrefix($string)
    {
        if ($string{0} === '-') {
            $pos = strpos($string, '-', 1);
            return substr($string, $pos + 1);
        }

        return $string;
    }
}
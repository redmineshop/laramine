<?php

namespace App\Http\Api;

/**
 * Writes a REST document as XML with Redmine element names.
 *
 * A list becomes `type="array"` and a singular child element. Collection
 * metadata (`total_count`, `offset`, `limit`) is emitted as attributes on
 * that list. Nulls use `nil="true"`.
 */
final class XmlDocument
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function render(array $document): string
    {
        $meta = [];
        $root = null;
        $value = null;
        foreach ($document as $key => $item) {
            if (in_array($key, ['total_count', 'offset', 'limit'], true)) {
                $meta[$key] = $item;

                continue;
            }
            $root = $key;
            $value = $item;
        }

        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        if (! is_string($root)) {
            return $body;
        }

        return $body.$this->element($root, $value, is_array($value) && array_is_list($value) ? $meta : []);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function element(string $name, mixed $value, array $meta = []): string
    {
        if ($value === null) {
            return '<'.$name.' nil="true"/>';
        }
        if (is_bool($value)) {
            return '<'.$name.'>'.($value ? 'true' : 'false').'</'.$name.'>';
        }
        if (is_int($value)) {
            return '<'.$name.'>'.$value.'</'.$name.'>';
        }
        if (is_float($value)) {
            return '<'.$name.'>'.$this->number($value).'</'.$name.'>';
        }
        if (is_string($value)) {
            return '<'.$name.'>'.$this->escape($value).'</'.$name.'>';
        }
        if (! is_array($value)) {
            return '<'.$name.' nil="true"/>';
        }

        if (array_is_list($value)) {
            $attributes = ' type="array"';
            foreach ($meta as $metaName => $metaValue) {
                if (is_scalar($metaValue)) {
                    $attributes .= ' '.$metaName.'="'.$this->escape((string) $metaValue).'"';
                }
            }
            $inner = '';
            $child = $this->singular($name);
            foreach ($value as $item) {
                $inner .= $this->element($child, $item);
            }

            return '<'.$name.$attributes.'>'.$inner.'</'.$name.'>';
        }

        $inner = '';
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $inner .= $this->element($key, $item);
            }
        }

        return '<'.$name.'>'.$inner.'</'.$name.'>';
    }

    private function singular(string $name): string
    {
        if ($name === 'news') {
            return 'news';
        }
        if ($name === 'children') {
            return 'child';
        }
        if (str_ends_with($name, 'statuses')) {
            return substr($name, 0, -2);
        }
        if (str_ends_with($name, 'ies')) {
            return substr($name, 0, -3).'y';
        }
        if (str_ends_with($name, 's') && ! str_ends_with($name, 'ss')) {
            return substr($name, 0, -1);
        }

        return $name;
    }

    private function number(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}

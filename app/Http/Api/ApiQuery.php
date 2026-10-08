<?php

namespace App\Http\Api;

use Illuminate\Http\Request;
use SimpleXMLElement;

/**
 * Reads include lists and the wrapped resource object from JSON or XML.
 */
final class ApiQuery
{
    /**
     * @return array<string, true>
     */
    public static function includes(Request $request): array
    {
        $raw = $request->query('include', '');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $names = [];
        foreach (explode(',', $raw) as $part) {
            $name = trim($part);
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    public static function resource(Request $request, string $root): array
    {
        $contentType = strtolower((string) $request->header('Content-Type'));
        $path = $request->getPathInfo();
        if (str_contains($contentType, 'xml') || str_ends_with($path, '.xml')) {
            return self::xmlResource($request->getContent(), $root);
        }

        $decoded = $request->json()->all();
        if (isset($decoded[$root]) && is_array($decoded[$root])) {
            return self::stringKeyed($decoded[$root]);
        }

        $form = $request->input($root);
        if (is_array($form)) {
            return self::stringKeyed($form);
        }

        return [];
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $keyed = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keyed[$key] = $item;
            }
        }

        return $keyed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function xmlResource(string $xml, string $root): array
    {
        if (trim($xml) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $parsed instanceof SimpleXMLElement) {
            return [];
        }

        $node = $parsed->getName() === $root ? $parsed : $parsed->{$root};
        if (! $node instanceof SimpleXMLElement) {
            return [];
        }

        $out = [];
        foreach ($node->children() as $child) {
            $out[$child->getName()] = self::xmlValue($child);
        }

        return $out;
    }

    private static function xmlValue(SimpleXMLElement $node): mixed
    {
        $children = $node->children();
        if (count($children) === 0) {
            return (string) $node;
        }

        $out = [];
        foreach ($children as $child) {
            $name = $child->getName();
            $value = self::xmlValue($child);
            if (array_key_exists($name, $out)) {
                $existing = $out[$name];
                if (! is_array($existing) || ! array_is_list($existing)) {
                    $out[$name] = [$existing];
                }
                if (is_array($out[$name])) {
                    $out[$name][] = $value;
                }
            } else {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}

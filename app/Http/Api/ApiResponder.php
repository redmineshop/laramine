<?php

namespace App\Http\Api;

use Symfony\Component\HttpFoundation\Response;

/**
 * Encodes an {@see ApiResult} as JSON or XML.
 */
final class ApiResponder
{
    public function __construct(
        private readonly XmlDocument $xml,
    ) {}

    public function send(string $format, ApiResult $result): Response
    {
        $headers = [];
        if ($result->location !== null) {
            $headers['Location'] = $result->location;
        }
        if ($result->body === null) {
            return response('', $result->status, $headers);
        }
        if ($format === 'xml') {
            $headers['Content-Type'] = 'application/xml; charset=utf-8';

            return response($this->xml->render($result->body), $result->status, $headers);
        }

        return response()->json($result->body, $result->status, $headers);
    }
}

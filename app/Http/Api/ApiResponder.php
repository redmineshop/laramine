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
        if ($result->body === null) {
            return response('', $result->status);
        }
        if ($format === 'xml') {
            return response($this->xml->render($result->body), $result->status, [
                'Content-Type' => 'application/xml; charset=utf-8',
            ]);
        }

        return response()->json($result->body, $result->status);
    }
}

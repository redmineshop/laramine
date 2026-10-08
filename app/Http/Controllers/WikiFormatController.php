<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\RestController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wiki page export and the JSON/XML page document share one URI.
 *
 * HTML, text, and PDF stay on the wiki export. JSON and XML use the REST
 * page document so the export route is not replaced.
 */
final class WikiFormatController extends Controller
{
    public function __construct(
        private readonly WikiController $wiki,
        private readonly RestController $rest,
    ) {}

    public function show(Request $request, string $project, string $title, string $format): Response
    {
        if ($format === 'json' || $format === 'xml') {
            return $this->rest->showWiki($request, $project, $title);
        }

        return $this->wiki->exportPage($request, $project, $title, $format);
    }
}

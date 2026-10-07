<?php

namespace App\Http\Controllers;

use App\Domain\Gantt\GanttChart;
use App\Domain\PermissionDeniedException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gantt rows and a text PDF of those labels. This page is not a Redmine screen.
 * PNG export is not produced.
 */
class GanttController extends Controller
{
    public function __construct(private readonly GanttChart $gantt) {}

    public function index(Request $request): Response
    {
        return $this->page($request, null);
    }

    public function project(Request $request, Project $project): Response
    {
        return $this->page($request, $project);
    }

    public function pdf(Request $request): HttpResponse
    {
        return $this->pdfResponse($request, null);
    }

    public function projectPdf(Request $request, Project $project): HttpResponse
    {
        return $this->pdfResponse($request, $project);
    }

    private function page(Request $request, ?Project $project): Response
    {
        $chart = $this->chart($request, $project);
        $rawRows = $chart['rows'] ?? null;
        $rows = [];
        if (is_array($rawRows)) {
            foreach ($rawRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[] = [
                    'kind' => is_string($row['kind'] ?? null) ? $row['kind'] : '',
                    'id' => is_int($row['id'] ?? null) ? $row['id'] : 0,
                    'indent' => is_int($row['indent'] ?? null) ? $row['indent'] : 0,
                    'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                ];
            }
        }

        return Inertia::render('Gantt/Show', [
            'projectId' => $project === null ? null : (int) $project->id,
            'year' => is_int($chart['year_from'] ?? null) ? $chart['year_from'] : 0,
            'month' => is_int($chart['month_from'] ?? null) ? $chart['month_from'] : 0,
            'months' => is_int($chart['months'] ?? null) ? $chart['months'] : 0,
            'zoom' => is_int($chart['zoom'] ?? null) ? $chart['zoom'] : 0,
            'truncated' => ($chart['truncated'] ?? null) === true,
            'png' => 'n/a',
            'rows' => $rows,
        ]);
    }

    private function pdfResponse(Request $request, ?Project $project): HttpResponse
    {
        $chart = $this->chart($request, $project);
        $pdf = $chart['pdf'] ?? '';

        return response(is_string($pdf) ? $pdf : '', 200, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chart(Request $request, ?Project $project): array
    {
        $options = [];
        $draw = $request->query('draw_relations');
        if ($draw === '0' || $draw === '1') {
            $options['draw_relations'] = $draw;
        }

        try {
            return $this->gantt->show(
                $this->actor($request),
                $project,
                [
                    'year' => $this->optionalInt($request, 'year'),
                    'month' => $this->optionalInt($request, 'month'),
                    'months' => $this->optionalInt($request, 'months'),
                    'zoom' => $this->optionalInt($request, 'zoom'),
                ],
                null,
                $options === [] ? null : $options,
            );
        } catch (PermissionDeniedException) {
            abort(403);
        }
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->query($key);
        if (! is_string($value) || preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}

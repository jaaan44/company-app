<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Admin\Reports;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download a report's CSV from the Backoffice (Phase 36D): the API's own
 * export (`GET /api/v1/reports/{report}/export`), called in-process as
 * the signed-in Administrator with the screen's filters, and streamed
 * back unchanged. The API decides the rows and audits the export.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, InternalApi $api, string $report): Response
    {
        $definition = Reports::all()[$report] ?? abort(404);

        try {
            $response = $api->raw($definition['path'].'/export', Reports::query($report, $request->query()));
        } catch (PortalApiException $e) {
            abort($e->isForbidden() ? 403 : 404);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

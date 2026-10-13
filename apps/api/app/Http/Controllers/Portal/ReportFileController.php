<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * A report's photo or file, for the Staff Web Portal (Phase 34E, W-8):
 * the API's own authorized download (`GET …/attachments/{id}/download`),
 * called in-process as the signed-in user and streamed back unchanged —
 * the API decides who may read it. A JPEG or PNG opens in the browser; any
 * other file (a PDF) downloads. The browser may not sniff or run it.
 */
class ReportFileController extends Controller
{
    public function __invoke(InternalApi $api, string $kind, string $publicId, string $attachmentId): Response
    {
        $base = $kind === 'incidents' ? '/incident-reports/' : '/service-reports/';

        try {
            $response = $api->raw($base.rawurlencode($publicId).'/attachments/'.rawurlencode($attachmentId).'/download');
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                Auth::guard('web')->logout();
                session()->invalidate();
                session()->regenerateToken();

                return redirect()->route('login')->with('status', 'Your session has ended. Please sign in again.');
            }
            abort(404);
        }

        $type = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));
        $inline = in_array($type, ['image/jpeg', 'image/png'], true);
        $disposition = (string) $response->headers->get('Content-Disposition');
        preg_match('/filename="?([^";]+)"?/', $disposition, $m);
        $name = $m[1] ?? 'file';
        $fallback = preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', $name) ?: 'file';

        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $fallback));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

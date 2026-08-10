<?php

namespace App\Http\Controllers;

use App\Support\MarketingMaterial;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the flyer PDFs listed on the admin panel's "Marketing material" page.
 *
 * Admin-gated rather than public: the material carries prices and an unsigned
 * commission line, and a flyer addressed to a named organisation is not something
 * to leave on an open URL. The files themselves are committed build artifacts, so
 * unlike the workbook downloads nothing here is generated or deleted on the fly.
 */
class MarketingMaterialDownloadController extends Controller
{
    public function __invoke(Request $request, string $item, string $variant): BinaryFileResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $file = MarketingMaterial::file($item, $variant);

        abort_if($file === null, 404);

        return response()->download($file['path'], $file['name'], [
            'Content-Type' => 'application/pdf',
        ]);
    }
}

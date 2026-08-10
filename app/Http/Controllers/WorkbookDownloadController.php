<?php

namespace App\Http\Controllers;

use App\Filament\Support\WorkbookDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the .xlsx files the admin panel generates (listing export, capture
 * template, import report). The signed URL is single-use — the token is pulled
 * from the cache on the first hit — and the file is deleted once sent, so
 * storage/app/exports doesn't fill up with stale workbooks.
 */
class WorkbookDownloadController extends Controller
{
    public function __invoke(Request $request, string $token): BinaryFileResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $entry = WorkbookDownload::claim($token);

        abort_if($entry === null || ! is_file($entry['path']), 404);

        return response()
            ->download($entry['path'], $entry['name'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands out a filed document to whoever is signed in to the admin panel.
 *
 * This is the *only* way the bytes leave the server: documents live on the
 * private 'local' disk precisely so that there is no second, unauthenticated
 * route to them (see the documents migration). Which means the check below is
 * the access rule, not a convenience — same admin gate the panel itself uses.
 */
class DocumentDownloadController extends Controller
{
    public function __invoke(Request $request, Document $document): StreamedResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        abort_unless($document->isFile(), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($document->disk ?: Document::DISK);

        abort_unless($disk->exists((string) $document->path), 404);

        // Images and PDFs are looked at far more often than they are saved, so
        // they open in the tab; everything else downloads.
        $inline = $document->isImage() || $document->mime_type === 'application/pdf';

        return $disk->response(
            (string) $document->path,
            $document->downloadName(),
            ['Content-Type' => $document->mime_type ?: 'application/octet-stream'],
            $inline ? 'inline' : 'attachment',
        );
    }
}

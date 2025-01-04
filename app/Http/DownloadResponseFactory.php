<?php

namespace BookStack\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadResponseFactory
{
    public function __construct(
        protected Request $request,
    ) {
    }

    /**
     * Create a response that directly forces a download in the browser.
     */
    public function directly(string $content, string $fileName): Response
    {
        return response()->make($content, 200, $this->getHeaders($fileName, strlen($content)));
    }

    /**
     * Create a response that forces a download, from a given stream of content.
     */
    public function streamedDirectly($stream, string $fileName, int $fileSize): StreamedResponse
    {
        $rangeStream = new RangeSupportedStream($stream, $fileSize, $this->request);
        $headers = array_merge($this->getHeaders($fileName, $fileSize), $rangeStream->getResponseHeaders());
        return response()->stream(
            fn() => $rangeStream->outputAndClose(),
            $rangeStream->getResponseStatus(),
            $headers,
        );
    }

    /**
     * Create a response that downloads the given file via a stream.
     * Has the option to delete the provided file once the stream is closed.
     */
    public function streamedFileDirectly(string $filePath, string $fileName, int $fileSize, bool $deleteAfter = false): StreamedResponse
    {
        $stream = fopen($filePath, 'r');

        if ($deleteAfter) {
            // Delete the given file if it still exists after the app terminates
            $callback = function () use ($filePath) {
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            };

            // We watch both app terminate and php shutdown to cover both normal app termination
            // as well as other potential scenarios (connection termination).
            app()->terminating($callback);
            register_shutdown_function($callback);
        }

        return $this->streamedDirectly($stream, $fileName, $fileSize);
    }


    /**
     * Create a file download response that provides the file with a content-type
     * correct for the file, in a way so the browser can show the content in browser,
     * for a given content stream.
     */
    public function streamedInline($stream, string $fileName, int $fileSize): StreamedResponse
    {
        $rangeStream = new RangeSupportedStream($stream, $fileSize, $this->request);
        $mime = $rangeStream->sniffMime();
        $headers = array_merge($this->getHeaders($fileName, $fileSize, $mime), $rangeStream->getResponseHeaders());

        return response()->stream(
            fn() => $rangeStream->outputAndClose(),
            $rangeStream->getResponseStatus(),
            $headers,
        );
    }

    /**
     * Get the common headers to provide for a download response.
     */
    protected function getHeaders(string $fileName, int $fileSize, string $mime = 'application/octet-stream'): array
    {
        $disposition = ($mime === 'application/octet-stream') ? 'attachment' : 'inline';
        $downloadName = str_replace('"', '', $fileName);

        return [
            'Content-Type'           => $mime,
            'Content-Length'         => $fileSize,
            'Content-Disposition'    => "{$disposition}; filename=\"{$downloadName}\"",
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}

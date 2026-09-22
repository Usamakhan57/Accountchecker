<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Checkers\CheckerRegistry;
use AccountCheck\Core\Config;
use AccountCheck\Core\HttpException;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Services\JobService;

/**
 * Batch job endpoints.
 *
 * Submitting returns as soon as the rows are written; nothing here waits for a
 * check to run. The client is given a job id and polls `progress`, which is why
 * a 5,000-record batch is possible without a long-lived request or a socket.
 *
 * Every read is scoped to the authenticated user inside the service, so a job
 * id belonging to someone else is indistinguishable from one that does not
 * exist.
 */
final class JobController extends Controller
{
    public function __construct(
        private readonly JobService $jobs,
        private readonly CheckerRegistry $registry,
        private readonly Config $config,
    ) {
    }

    /**
     * Starts a job from a pasted list or an uploaded file.
     *
     * POST /api/checker/{slug}/start
     */
    public function start(Request $request): Response
    {
        $user = $this->user($request);
        $slug = (string) $request->routeParam('slug', '');
        $checker = $this->registry->get($slug);

        $upload = $request->file('file');
        $source = 'PASTE';

        if ($upload !== null) {
            $input = $this->readUpload($upload);
            $source = 'UPLOAD';
        } else {
            $validated = $this->validate($request, [
                'input' => 'required|string|max:2000000',
            ]);
            $input = (string) $validated['input'];
        }

        $options = [];
        $format = $request->input('output_format');

        if (is_string($format) && in_array(strtolower($format), ['csv', 'txt'], true)) {
            $options['output_format'] = strtolower($format);
        }

        $created = $this->jobs->create(
            $user,
            $checker->getCapabilities()->slug,
            $input,
            $source,
            $options,
            $request->ip(),
        );

        return Response::created($created, 'Job queued');
    }

    /** GET /api/jobs */
    public function index(Request $request): Response
    {
        $user = $this->user($request);

        $page = $this->jobs->paginate($user->id, $this->paginator($request), [
            'status' => $request->query('status'),
            'checker' => $request->query('checker'),
        ]);

        return Response::success($page, 'Your jobs');
    }

    /** GET /api/jobs/{id} */
    public function show(Request $request): Response
    {
        $user = $this->user($request);

        return Response::success(
            $this->jobs->show($user->id, (string) $request->routeParam('id', '')),
            'Job details',
        );
    }

    /**
     * GET /api/jobs/{id}/progress
     *
     * The polling endpoint. Small on purpose.
     */
    public function progress(Request $request): Response
    {
        $user = $this->user($request);

        return Response::success(
            $this->jobs->progress($user->id, (string) $request->routeParam('id', '')),
            'Job progress',
        );
    }

    /** POST /api/jobs/{id}/cancel */
    public function cancel(Request $request): Response
    {
        $user = $this->user($request);

        return Response::success(
            $this->jobs->cancel($user->id, (string) $request->routeParam('id', ''), $request->ip()),
            'Job cancelled',
        );
    }

    /**
     * Reads an uploaded list.
     *
     * Uploads are read as text and never stored, executed or served back: the
     * file's only job is to carry lines. The extension allow-list and the size
     * cap are both checked here rather than trusted from the client.
     *
     * @param array<string, mixed> $upload
     */
    private function readUpload(array $upload): string
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException('That file is too large.', 413, 'UPLOAD_TOO_LARGE');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest('The file could not be read.', 'UPLOAD_FAILED');
        }

        $maxBytes = max(1024, $this->config->int('checkers.uploads.max_bytes', 5_242_880));

        if ((int) ($upload['size'] ?? 0) > $maxBytes) {
            throw new HttpException(
                sprintf('That file is larger than %d MB.', (int) floor($maxBytes / 1_048_576)),
                413,
                'UPLOAD_TOO_LARGE',
            );
        }

        $allowed = $this->config->array('checkers.uploads.allowed_extensions', ['txt', 'csv']);
        $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));

        if (!in_array($extension, array_map('strtolower', $allowed), true)) {
            throw HttpException::badRequest(
                'Upload a .txt or .csv file.',
                'UPLOAD_TYPE_NOT_ALLOWED',
            );
        }

        $path = (string) ($upload['tmp_name'] ?? '');

        // The temporary path must be one PHP itself created, or a crafted
        // request could name any file on disk.
        if ($path === '' || !is_uploaded_file($path)) {
            throw HttpException::badRequest('The file could not be read.', 'UPLOAD_FAILED');
        }

        $contents = file_get_contents($path, false, null, 0, $maxBytes + 1);

        if ($contents === false) {
            throw HttpException::badRequest('The file could not be read.', 'UPLOAD_FAILED');
        }

        if (strlen($contents) > $maxBytes) {
            throw new HttpException('That file is too large.', 413, 'UPLOAD_TOO_LARGE');
        }

        // Anything that is not valid UTF-8 is not a list of handles or
        // addresses; rejecting it is safer than transcoding a binary file.
        if (!mb_check_encoding($contents, 'UTF-8')) {
            throw HttpException::badRequest('That file is not a plain text list.', 'UPLOAD_NOT_TEXT');
        }

        return $contents;
    }
}

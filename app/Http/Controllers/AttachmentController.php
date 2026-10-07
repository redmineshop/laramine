<?php

namespace App\Http\Controllers;

use App\Domain\AttachmentArchiveLimitException;
use App\Domain\Attachments\AttachmentArchive;
use App\Domain\Attachments\AttachmentContainerService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves container attachments and accepts an upload token.
 *
 * The signed-in user is the session user. A guest is a null actor. This
 * controller does not sign anyone in. Custom-field files stay on the
 * custom-field routes.
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentContainerService $attachments,
        private readonly AttachmentArchive $archives,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Sign in to upload a file.'], 403);
        }
        $filename = $request->query('filename');
        if (! is_string($filename) || $filename === '') {
            return response()->json(['message' => 'Filename is not valid.'], 422);
        }
        $contentType = $request->query('content_type');
        try {
            $uploaded = $this->attachments->upload(
                $actor,
                $filename,
                $request->getContent(),
                is_string($contentType) && $contentType !== '' ? $contentType : null,
            );
        } catch (PermissionDeniedException $denied) {
            return $this->denied($denied);
        } catch (DomainException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['token' => $uploaded['token']]);
    }

    public function claim(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Sign in to upload a file.'], 403);
        }
        try {
            $attachment = $this->attachments->claim(
                $actor,
                $this->stringInput($request, 'token') ?? '',
                $this->intInput($request, 'issue_id'),
                $this->intInput($request, 'journal_id'),
                $this->stringInput($request, 'filename'),
                $this->stringInput($request, 'description'),
            );
        } catch (PermissionDeniedException $denied) {
            return $this->denied($denied);
        } catch (DomainException $exception) {
            return $this->invalid($exception);
        }

        return response()->json([
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'container_type' => $attachment->container_type,
            'container_id' => $attachment->container_id,
        ]);
    }

    public function download(Request $request, Attachment $attachment): BinaryFileResponse|JsonResponse
    {
        return $this->send($this->actor($request), $attachment, null, false);
    }

    public function downloadAll(Request $request, string $objectType, int $objectId): Response|JsonResponse
    {
        try {
            $zip = $this->archives->downloadBundle($this->actor($request), $objectType, $objectId);
        } catch (PermissionDeniedException $denied) {
            return $this->denied($denied);
        } catch (AttachmentArchiveLimitException $limit) {
            return response()->json(['message' => $limit->getMessage()], 422);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        $response = response($zip->contents, 200, ['Content-Type' => 'application/zip']);
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="'.$zip->filename.'"',
        );

        return $response;
    }

    public function thumbnail(Request $request, Attachment $attachment): BinaryFileResponse|JsonResponse
    {
        $size = $request->query('size');
        $requested = is_string($size) && preg_match('/^\d+$/', $size) === 1 ? (int) $size : null;

        return $this->send($this->actor($request), $attachment, $requested, true);
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse|Response
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Sign in to upload a file.'], 403);
        }
        try {
            $this->attachments->delete($actor, $attachment);
        } catch (PermissionDeniedException $denied) {
            return $this->denied($denied);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        return response()->noContent();
    }

    private function send(?User $actor, Attachment $attachment, ?int $size, bool $thumbnail): BinaryFileResponse|JsonResponse
    {
        try {
            $file = $thumbnail
                ? $this->attachments->thumbnail($actor, $attachment, $size)
                : $this->attachments->download($actor, $attachment);
        } catch (PermissionDeniedException $denied) {
            return $this->denied($denied);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        $response = response()->file($file->absolutePath, ['Content-Type' => $file->contentType]);
        $response->setContentDisposition($file->disposition, $file->filename);

        return $response;
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new DomainException('Filename is not valid.');
        }

        return $value;
    }

    private function intInput(Request $request, string $key): ?int
    {
        if (! $request->exists($key) || $request->input($key) === null || $request->input($key) === '') {
            return null;
        }
        $value = $request->input($key);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new DomainException('Attachment needs an issue or a journal.');
    }

    private function denied(PermissionDeniedException $denied): JsonResponse
    {
        return response()->json(['message' => $denied->getMessage()], 403);
    }

    private function missing(DomainException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], 404);
    }

    private function invalid(DomainException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], 422);
    }
}

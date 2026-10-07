<?php

namespace App\Http\Controllers;

use App\Domain\CustomFields\CustomFieldAssetAccess;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Models\Attachment;
use App\Models\CustomValue;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves or deletes a custom-field attachment and resolves a link custom value.
 *
 * The signed-in user is the session user. A guest is a null actor. This
 * controller does not sign anyone in.
 */
class CustomFieldAssetController extends Controller
{
    public function __construct(private readonly CustomFieldAssetAccess $access) {}

    public function download(Request $request, Attachment $attachment): BinaryFileResponse|JsonResponse
    {
        try {
            $file = $this->access->download($this->actor($request), $attachment);
        } catch (PermissionDeniedException $denied) {
            return $this->denied($denied);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        $headers = [];
        if ($file->contentType !== null) {
            $headers['Content-Type'] = $file->contentType;
        }

        return response()->download($file->absolutePath, $file->filename, $headers);
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse|Response
    {
        try {
            $this->access->delete($this->actor($request), $attachment);
        } catch (PermissionDeniedException $denied) {
            return $this->denied($denied);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        return response()->noContent();
    }

    public function show(Request $request, CustomValue $customValue): JsonResponse
    {
        try {
            $link = $this->access->link($this->actor($request), $customValue);
        } catch (PermissionDeniedException $denied) {
            return $this->denied($denied);
        } catch (DomainException $exception) {
            return $this->missing($exception);
        }

        return response()->json([
            'value' => $link->value,
            'url' => $link->url,
        ]);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function denied(PermissionDeniedException $denied): JsonResponse
    {
        return response()->json(['message' => $denied->getMessage()], 403);
    }

    private function missing(DomainException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], 404);
    }
}

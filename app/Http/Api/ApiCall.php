<?php

namespace App\Http\Api;

use App\Domain\Attachments\AttachmentTooLargeException;
use App\Domain\Auth\AccountValidationException;
use App\Domain\CustomFields\CustomFieldValidationException;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\Wiki\WikiVersionConflictException;

/**
 * Turns domain failures into REST error documents.
 */
final class ApiCall
{
    /**
     * @param  callable(): ApiResult  $callback
     */
    public function run(callable $callback): ApiResult
    {
        try {
            return $callback();
        } catch (PermissionDeniedException) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        } catch (AccountValidationException $exception) {
            return ApiResult::fail(422, ...$this->accountMessages($exception));
        } catch (CustomFieldValidationException $exception) {
            return ApiResult::fail(422, ...$this->customFieldMessages($exception));
        } catch (WikiVersionConflictException $exception) {
            return ApiResult::fail(409, $exception->getMessage());
        } catch (AttachmentTooLargeException $exception) {
            return ApiResult::fail(413, $exception->getMessage());
        } catch (DomainException $exception) {
            return ApiResult::fail(422, $exception->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function collection(string $key, array $rows, ApiPage $page, int $total): array
    {
        $body = [$key => $rows];
        if (! $page->nometa) {
            $body['total_count'] = $total;
            $body['offset'] = $page->offset;
            $body['limit'] = $page->limit;
        }

        return $body;
    }

    /**
     * @return list<string>
     */
    private function accountMessages(AccountValidationException $exception): array
    {
        $messages = [];
        foreach ($exception->errors as $lines) {
            foreach ($lines as $line) {
                $messages[] = $line;
            }
        }

        return $messages === [] ? ['Account validation failed.'] : $messages;
    }

    /**
     * @return list<string>
     */
    private function customFieldMessages(CustomFieldValidationException $exception): array
    {
        $messages = [];
        foreach ($exception->errors as $error) {
            foreach ($error['messages'] as $message) {
                $messages[] = $error['name'].': '.$message;
            }
        }

        return $messages === [] ? ['Custom field validation failed.'] : $messages;
    }
}

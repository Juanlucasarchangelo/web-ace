<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\GoogleDriveConnection;
use App\Services\GoogleDriveManagerService;
use App\Support\PlanFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Additional file management API. Existing OAuth controller/routes remain untouched. */
class GoogleDriveManagerController extends AceController
{
    private function connection(Request $request, bool $write = false, bool $create = false): GoogleDriveConnection
    {
        $tenant = $this->tenant($request);
        abort_unless(PlanFeatures::cloud($tenant), 403, 'Google Drive requer plano Cloud ou Pro.');
        if ($write) {
            $this->canWrite($request);
        }
        if ($create) {
            $this->canCreate($request);
        }
        $query = GoogleDriveConnection::where('tenant_id', $tenant->id);
        $accountId = $request->input('account_id');
        if ($accountId !== null && $accountId !== '') {
            abort_unless(ctype_digit((string) $accountId), 422, 'Conta inválida.');
            $query->whereKey((int) $accountId);
        }
        return $query->orderBy('id')->first()
            ?? throw ValidationException::withMessages(['drive' => 'Conta Google Drive não conectada ao workspace.']);
    }

    public function index(Request $request, GoogleDriveManagerService $drive): JsonResponse
    {
        $data = $request->validate([
            'folder_id' => ['sometimes', 'string', 'max:200'],
            'page_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        return response()->json(['data' => $drive->listFolder(
            $this->connection($request),
            $data['folder_id'] ?? 'root',
            $data['page_token'] ?? null
        )]);
    }

    public function createFolder(Request $request, GoogleDriveManagerService $drive): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:180'],
            'parent_id' => ['sometimes', 'string', 'max:200'],
        ]);
        $result = $drive->createFolder(
            $this->connection($request, false, true), $data['name'], $data['parent_id'] ?? 'root'
        );
        $this->logAction($request, 'cloud.google.folder.created');
        return response()->json(['data' => $result], 201);
    }

    public function upload(Request $request, GoogleDriveManagerService $drive): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'parent_id' => ['sometimes', 'string', 'max:200'],
        ]);
        $result = $drive->uploadToFolder(
            $this->connection($request, false, true), $data['file'], $data['parent_id'] ?? 'root'
        );
        $this->logAction($request, 'cloud.google.file.uploaded');
        return response()->json(['data' => $result], 201);
    }

    public function rename(Request $request, string $fileId, GoogleDriveManagerService $drive): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:180']]);
        $result = $drive->rename($this->connection($request, true), $fileId, $data['name']);
        $this->logAction($request, 'cloud.google.item.renamed');
        return response()->json(['data' => $result]);
    }

    public function move(Request $request, string $fileId, GoogleDriveManagerService $drive): JsonResponse
    {
        $data = $request->validate(['destination_id' => ['required', 'string', 'max:200']]);
        $result = $drive->move($this->connection($request, true), $fileId, $data['destination_id']);
        $this->logAction($request, 'cloud.google.item.moved');
        return response()->json(['data' => $result]);
    }

    public function trash(Request $request, string $fileId, GoogleDriveManagerService $drive): JsonResponse
    {
        $result = $drive->trash($this->connection($request, true), $fileId);
        $this->logAction($request, 'cloud.google.item.trashed');
        return response()->json(['data' => $result]);
    }

    public function preview(Request $request, string $fileId, GoogleDriveManagerService $drive)
    {
        $connection = $this->connection($request);
        $metadata = $drive->metadata($connection, $fileId);
        $mime = (string) ($metadata['mimeType'] ?? '');
        $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        abort_unless(in_array($mime, $allowed, true), 422, 'Prévia indisponível para este tipo de arquivo.');
        $data = $drive->download($connection, $fileId);
        return response($data['body'], 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.str_replace(
                ['"', "\r", "\n"], '_', basename($data['name'])
            ).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
    public function download(Request $request, string $fileId, GoogleDriveManagerService $drive)
    {
        $data = $drive->download($this->connection($request), $fileId);
        $this->logAction($request, 'cloud.google.file.downloaded');
        return response($data['body'], 200, [
            'Content-Type' => $data['mime'],
            'Content-Disposition' => 'attachment; filename="'.str_replace(['"', "\r", "\n"], '_', basename($data['name'])).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

}

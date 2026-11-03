<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Operations for ACE's file manager. Extends the working token refresh and download
 * implementation instead of altering the existing Google Drive OAuth integration.
 */
class GoogleDriveManagerService extends GoogleDriveService
{
    private const API = 'https://www.googleapis.com/drive/v3/files';
    private const FOLDER = 'application/vnd.google-apps.folder';
    private const FIELDS = 'id,name,mimeType,size,modifiedTime,parents,description';

    public function listFolder(
        GoogleDriveConnection $connection,
        string $folderId = 'root',
        ?string $pageToken = null
    ): array {
        $folderId = $this->folderId($folderId);
        $query = [
            'pageSize' => 100,
            'fields' => 'nextPageToken,files('.self::FIELDS.')',
            'orderBy' => 'folder,name',
            'q' => "'".$this->escapeQuery($folderId)."' in parents and trashed = false",
        ];
        if ($pageToken) {
            $query['pageToken'] = $pageToken;
        }

        return $this->result(Http::withToken($this->accessToken($connection))
            ->timeout(25)->get(self::API, $query));
    }

    public function createFolder(
        GoogleDriveConnection $connection,
        string $name,
        string $parentId = 'root'
    ): array {
        $parentId = $this->folderId($parentId);
        $response = Http::withToken($this->accessToken($connection))
            ->timeout(25)->post(self::API.'?fields='.rawurlencode(self::FIELDS), [
                'name' => $name,
                'mimeType' => self::FOLDER,
                'parents' => [$parentId],
            ]);
        return $this->result($response);
    }

    public function uploadToFolder(
        GoogleDriveConnection $connection,
        UploadedFile $file,
        string $parentId = 'root'
    ): array {
        $parentId = $this->folderId($parentId);
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o arquivo.']);
        }
        $mime = preg_replace('/[^a-zA-Z0-9.\-+\/]/', '', $file->getMimeType() ?: 'application/octet-stream');
        $boundary = 'ace_'.bin2hex(random_bytes(16));
        $metadata = json_encode([
            'name' => $file->getClientOriginalName(),
            'parents' => [$parentId],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
            ."--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n".$binary."\r\n--{$boundary}--\r\n";

        return $this->result(Http::withToken($this->accessToken($connection))
            ->timeout(55)->withBody($body, 'multipart/related; boundary='.$boundary)
            ->post('https://www.googleapis.com/upload/drive/v3/files?'.http_build_query([
                'uploadType' => 'multipart', 'fields' => self::FIELDS,
            ], '', '&', PHP_QUERY_RFC3986)));
    }

    public function rename(GoogleDriveConnection $connection, string $fileId, string $name): array
    {
        return $this->result(Http::withToken($this->accessToken($connection))
            ->timeout(25)->patch($this->url($fileId, ['fields' => self::FIELDS]), [
                'name' => $name,
            ]));
    }

    public function trash(GoogleDriveConnection $connection, string $fileId): array
    {
        return $this->result(Http::withToken($this->accessToken($connection))
            ->timeout(25)->patch($this->url($fileId, ['fields' => self::FIELDS]), [
                'trashed' => true,
            ]));
    }

    public function move(
        GoogleDriveConnection $connection,
        string $fileId,
        string $destinationId = 'root'
    ): array {
        $destinationId = $this->folderId($destinationId);
        $token = $this->accessToken($connection);
        $file = $this->metadata($connection, $fileId);
        if (($file['trashed'] ?? false) || ! isset($file['id'])) {
            throw ValidationException::withMessages(['drive' => 'O item não está disponível.']);
        }
        if ($fileId === $destinationId) {
            throw ValidationException::withMessages(['drive' => 'Uma pasta não pode ser movida para dentro dela mesma.']);
        }

        if ($destinationId !== 'root') {
            $destination = $this->metadata($connection, $destinationId);
            if (($destination['mimeType'] ?? null) !== self::FOLDER || ($destination['trashed'] ?? false)) {
                throw ValidationException::withMessages(['drive' => 'O destino selecionado não é uma pasta disponível.']);
            }
        }

        if (($file['mimeType'] ?? '') === self::FOLDER && $destinationId !== 'root') {
            // An ancestor check gives a clear message without relying on Google's error text.
            $ancestor = $destinationId;
            for ($depth = 0; $depth < 60; $depth++) {
                if ($ancestor === $fileId) {
                    throw ValidationException::withMessages([
                        'drive' => 'Não é possível mover uma pasta para uma de suas subpastas.',
                    ]);
                }
                $metadata = $ancestor === $destinationId
                    ? $destination
                    : $this->metadata($connection, $ancestor);
                $parents = $metadata['parents'] ?? [];
                if (! count($parents)) {
                    break;
                }
                $ancestor = (string) $parents[0];
            }
        }

        $parents = array_values(array_filter((array) ($file['parents'] ?? []), 'is_string'));
        if (in_array($destinationId, $parents, true)) {
            return $file;
        }
        $query = [
            'addParents' => $destinationId,
            'fields' => self::FIELDS,
        ];
        if ($parents) {
            $query['removeParents'] = implode(',', $parents);
        }

        return $this->result(Http::withToken($token)->timeout(30)
            ->withBody('{}', 'application/json')
            ->send('PATCH', $this->url($fileId, $query)));
    }

    public function metadata(GoogleDriveConnection $connection, string $fileId): array
    {
        return $this->result(Http::withToken($this->accessToken($connection))
            ->timeout(20)->get($this->url($fileId, [
                'fields' => self::FIELDS.',trashed',
            ])));
    }

    private function url(string $fileId, array $query = []): string
    {
        $url = self::API.'/'.rawurlencode($this->folderId($fileId));
        return $query ? $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;
    }

    private function folderId(string $id): string
    {
        if ($id !== 'root' && ! preg_match('/^[a-zA-Z0-9_-]{5,200}$/D', $id)) {
            throw ValidationException::withMessages(['drive' => 'Identificador de pasta ou arquivo inválido.']);
        }
        return $id;
    }

    private function escapeQuery(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    private function result(Response $response): array
    {
        if (! $response->successful()) {
            $status = $response->status();
            $message = match ($status) {
                403 => 'Sem permissão para essa operação no Google Drive.',
                404 => 'Arquivo ou pasta não encontrado ou sem acesso pelo ACE.',
                409 => 'Conflito ao atualizar o arquivo ou pasta.',
                default => 'Não foi possível concluir a operação no Google Drive.',
            };
            throw ValidationException::withMessages(['drive' => $message]);
        }
        return (array) $response->json();
    }
}

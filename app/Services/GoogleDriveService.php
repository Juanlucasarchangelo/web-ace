<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Client\Response;

class GoogleDriveService
{
    public function accessToken(GoogleDriveConnection $connection): string
    {
        if ($connection->expires_at && $connection->expires_at->isAfter(now()->addMinutes(2))) {
            return $connection->access_token;
        }

        return app(GoogleDriveHealth::class)->refresh($connection);
    }

    public function listFiles(GoogleDriveConnection $connection, ?string $pageToken = null): array
    {
        $query = [
            'pageSize' => 60,
            'fields' => 'nextPageToken,files(id,name,mimeType,size,modifiedTime,webViewLink,description)',
            'orderBy' => 'folder,name',
            'q' => 'trashed = false',
        ];
        if ($pageToken) $query['pageToken'] = $pageToken;
        $response = Http::withToken($this->accessToken($connection))->timeout(25)
            ->get('https://www.googleapis.com/drive/v3/files', $query);
        return $this->result($response, $connection);
    }

    public function folder(GoogleDriveConnection $connection, string $name): array
    {
        $response = Http::withToken($this->accessToken($connection))->timeout(25)
            ->post('https://www.googleapis.com/drive/v3/files?fields=id,name,mimeType,webViewLink', [
                'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder',
            ]);
        return $this->result($response, $connection);
    }

    public function upload(GoogleDriveConnection $connection, UploadedFile $file): array
    {
        $name = $file->getClientOriginalName();
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $boundary = 'ace_'.bin2hex(random_bytes(16));
        $metadata = json_encode(['name' => $name], JSON_THROW_ON_ERROR);
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o arquivo.']);
        }
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
            ."--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n".$binary."\r\n--{$boundary}--\r\n";
        $response = Http::withToken($this->accessToken($connection))->timeout(55)
            ->withBody($body, 'multipart/related; boundary='.$boundary)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType,size,webViewLink');
        return $this->result($response, $connection);
    }

    public function download(GoogleDriveConnection $connection, string $fileId): array
    {
        $token = $this->accessToken($connection);
        $metadata = $this->result(Http::withToken($token)->timeout(20)->get(
            'https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId),
            ['fields' => 'id,name,mimeType,size']
        ), $connection);
        if (str_starts_with((string) ($metadata['mimeType'] ?? ''), 'application/vnd.google-apps.')) {
            throw ValidationException::withMessages(['drive' => 'Documentos Google nativos devem ser abertos pelo Google Drive.']);
        }
        if ((int) ($metadata['size'] ?? 0) > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['drive' => 'Download acima do limite de 10 MB desta versão.']);
        }
        $response = Http::withToken($token)->timeout(35)->get(
            'https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId), ['alt' => 'media']
        );
        app(GoogleDriveHealth::class)->observeApi($connection, $response);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['drive' => GoogleDriveHealth::message(app(GoogleDriveHealth::class)->codeFor($response))]);
        }
        return ['name' => $metadata['name'], 'mime' => $metadata['mimeType'] ?? 'application/octet-stream', 'body' => $response->body()];
    }

    protected function result(Response $response, GoogleDriveConnection $connection): array
    {
        $health = app(GoogleDriveHealth::class);
        $health->observeApi($connection, $response);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['drive' => GoogleDriveHealth::message($health->codeFor($response))]);
        }
        return (array) $response->json();
    }
}

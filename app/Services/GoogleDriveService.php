<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GoogleDriveService
{
    public function accessToken(GoogleDriveConnection $connection): string
    {
        if ($connection->expires_at && $connection->expires_at->isAfter(now()->addMinutes(2))) {
            return $connection->access_token;
        }

        if (! $connection->refresh_token) {
            throw ValidationException::withMessages(['drive' => 'Autorização expirada. Conecte a conta novamente.']);
        }

        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful() || ! $response->json('access_token')) {
            throw ValidationException::withMessages(['drive' => 'Não foi possível renovar a autorização. Conecte o Google Drive novamente.']);
        }

        $connection->update([
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?: $connection->refresh_token,
            'expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
        ]);

        return $connection->access_token;
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
        return $this->result($response);
    }

    public function folder(GoogleDriveConnection $connection, string $name): array
    {
        $response = Http::withToken($this->accessToken($connection))->timeout(25)
            ->post('https://www.googleapis.com/drive/v3/files?fields=id,name,mimeType,webViewLink', [
                'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder',
            ]);
        return $this->result($response);
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
        return $this->result($response);
    }

    public function download(GoogleDriveConnection $connection, string $fileId): array
    {
        $token = $this->accessToken($connection);
        $metadata = $this->result(Http::withToken($token)->timeout(20)->get(
            'https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId),
            ['fields' => 'id,name,mimeType,size']
        ));
        if (str_starts_with((string) ($metadata['mimeType'] ?? ''), 'application/vnd.google-apps.')) {
            throw ValidationException::withMessages(['drive' => 'Documentos Google nativos devem ser abertos pelo Google Drive.']);
        }
        if ((int) ($metadata['size'] ?? 0) > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['drive' => 'Download acima do limite de 10 MB desta versão.']);
        }
        $response = Http::withToken($token)->timeout(35)->get(
            'https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId), ['alt' => 'media']
        );
        if (! $response->successful()) {
            throw ValidationException::withMessages(['drive' => 'Não foi possível baixar esse arquivo do Google Drive.']);
        }
        return ['name' => $metadata['name'], 'mime' => $metadata['mimeType'] ?? 'application/octet-stream', 'body' => $response->body()];
    }

    private function result(\Illuminate\Http\Client\Response $response): array
    {
        if (! $response->successful()) {
            throw ValidationException::withMessages(['drive' => 'O Google Drive recusou a operação. Confira as permissões e a conexão.']);
        }
        return (array) $response->json();
    }
}

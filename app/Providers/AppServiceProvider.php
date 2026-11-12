<?php

namespace App\Providers;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FirestoreClient::class, function ($app) {
            $credentialsPath = $app['config']->get(
                'firebase.projects.app.credentials'
            );

            if (!is_string($credentialsPath) || !is_file($credentialsPath)) {
                throw new RuntimeException(
                    'No se encontró el archivo de credenciales de Firebase.'
                );
            }

            $credentials = json_decode(
                file_get_contents($credentialsPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (empty($credentials['project_id'])) {
                throw new RuntimeException(
                    'Las credenciales de Firebase no contienen project_id.'
                );
            }

            $auth = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/cloud-platform'],
                $credentials
            );

            return new FirestoreClient([
                'projectId' => $credentials['project_id'],
                'credentials' => $auth,
                'transport' => 'rest',
            ]);
        });
    }

    public function boot(): void
    {
        //
    }
}
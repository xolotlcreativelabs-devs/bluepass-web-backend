<?php

namespace App\Providers;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Registrar la conexión con Firestore.
     */
    public function register(): void
    {
        $this->app->singleton(FirestoreClient::class, function ($app) {

            // Obtener y validar la ruta de las credenciales.
            $configuredPath = $app['config']->get(
                'firebase.projects.app.credentials'
            );

            if (!is_string($configuredPath) || $configuredPath === '') {
                throw new RuntimeException(
                    'No se configuró la ruta de credenciales de Firebase.'
                );
            }

            $credentialsPath = base_path($configuredPath);

            if (!is_file($credentialsPath) || !is_readable($credentialsPath)) {
                throw new RuntimeException(
                    'No se encontró el archivo de credenciales de Firebase.'
                );
            }

            // Cargar y validar las credenciales.
            $credentials = json_decode(
                file_get_contents($credentialsPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (
                !is_array($credentials) ||
                empty($credentials['project_id'])
            ) {
                throw new RuntimeException(
                    'Las credenciales de Firebase no contienen un project_id válido.'
                );
            }

            $auth = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/cloud-platform'],
                $credentials
            );

            // Conectar Firestore mediante REST.
            return new FirestoreClient([
                'projectId' => $credentials['project_id'],
                'credentials' => $auth,
                'transport' => 'rest',
            ]);
        });
    }

    /**
     * Configurar los límites de solicitudes de la API.
     */
    public function boot(): void
    {
        // Limitar las solicitudes de autenticación.
        RateLimiter::for('bluepass-auth', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip() ?? 'unknown');
        });

        // Limitar las solicitudes de consulta y actualización de perfiles.
        RateLimiter::for('bluepass-api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip() ?? 'unknown');
        });
    }
}

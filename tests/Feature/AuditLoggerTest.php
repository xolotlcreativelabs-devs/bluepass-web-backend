<?php

namespace Tests\Feature;

use App\Services\AuditLogger;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    /**
     * Registrar correctamente una acción en Firestore.
     */
    public function test_can_record_audit_log(): void
    {
        $firestore = $this->mockSuccessfulAudit([
            'action' => 'published',
            'resourceType' => 'news',
            'resourceId' => 'noticia-prueba',
            'actorId' => 'usuario-prueba',
            'actorRole' => 'seller',
            'changedFields' => ['status'],
        ]);

        $result = (new AuditLogger())->record(
            $firestore,
            'published',
            'news',
            'noticia-prueba',
            'usuario-prueba',
            'seller',
            ['status']
        );

        $this->assertTrue($result);
    }

    /**
     * Rechazar tipos de recursos desconocidos.
     */
    public function test_rejects_unknown_resource_type(): void
    {
        $firestore = $this->mockUnusedFirestore();

        $result = (new AuditLogger())->record(
            $firestore,
            'created',
            'payments',
            'pago-prueba',
            'usuario-prueba',
            'seller'
        );

        $this->assertFalse($result);
    }

    /**
     * Rechazar acciones que no estén permitidas.
     */
    public function test_rejects_unknown_action(): void
    {
        $firestore = $this->mockUnusedFirestore();

        $result = (new AuditLogger())->record(
            $firestore,
            'deleted',
            'news',
            'noticia-prueba',
            'usuario-prueba',
            'seller'
        );

        $this->assertFalse($result);
    }

    /**
     * Rechazar identificadores vacíos.
     */
    public function test_rejects_empty_identifiers(): void
    {
        $firestore = $this->mockUnusedFirestore();

        $logger = new AuditLogger();

        $this->assertFalse($logger->record(
            $firestore,
            'updated',
            'events',
            '',
            'usuario-prueba',
            'seller'
        ));

        $this->assertFalse($logger->record(
            $firestore,
            'updated',
            'events',
            'evento-prueba',
            '  ',
            'seller'
        ));
    }

    /**
     * Rechazar roles sin permiso para auditar cambios.
     */
    public function test_rejects_unauthorized_role(): void
    {
        $firestore = $this->mockUnusedFirestore();

        $result = (new AuditLogger())->record(
            $firestore,
            'updated',
            'events',
            'evento-prueba',
            'usuario-prueba',
            'customer'
        );

        $this->assertFalse($result);
    }

    /**
     * Filtrar campos no permitidos y eliminar duplicados.
     */
    public function test_filters_sensitive_and_duplicate_fields(): void
    {
        $firestore = $this->mockSuccessfulAudit([
            'action' => 'updated',
            'resourceType' => 'news',
            'resourceId' => 'noticia-prueba',
            'actorId' => 'usuario-prueba',
            'actorRole' => 'admin',
            'changedFields' => ['title', 'summary'],
        ]);

        $result = (new AuditLogger())->record(
            $firestore,
            'updated',
            'news',
            'noticia-prueba',
            'usuario-prueba',
            'admin',
            [
                'title',
                'password',
                'summary',
                'token',
                'title',
            ]
        );

        $this->assertTrue($result);
    }

    /**
     * Manejar errores de Firestore sin exponer detalles.
     */
    public function test_handles_firestore_failure(): void
    {
        Log::spy();

        $firestore = Mockery::mock(FirestoreClient::class);

        $firestore->shouldReceive('collection')
            ->once()
            ->with('audit_logs')
            ->andThrow(new \RuntimeException(
                'Error simulado de Firestore'
            ));

        $result = (new AuditLogger())->record(
            $firestore,
            'published',
            'events',
            'evento-prueba',
            'usuario-prueba',
            'seller',
            ['status']
        );

        $this->assertFalse($result);

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'No fue posible registrar la auditoría',
                [
                    'action' => 'published',
                    'resourceType' => 'events',
                    'exception' => \RuntimeException::class,
                ]
            );
    }

    /**
     * Preparar un registro para la creación de un evento.
     */
    public function test_prepare_creates_event_audit_data(): void
    {
        $logger = new AuditLogger();

        $data = $logger->prepare(
            'created',
            'events',
            'evento-prueba',
            'usuario-prueba',
            'seller',
            [
                'name',
                'description',
                'category',
                'venue',
                'timezone',
                'date',
                'endAt',
            ]
        );

        $this->assertIsArray($data);

        $this->assertSame('created', $data['action']);
        $this->assertSame('events', $data['resourceType']);
        $this->assertSame('evento-prueba', $data['resourceId']);
        $this->assertSame('usuario-prueba', $data['actorId']);
        $this->assertSame('seller', $data['actorRole']);

        $this->assertSame([
            'name',
            'description',
            'category',
            'venue',
            'timezone',
            'date',
            'endAt',
        ], $data['changedFields']);

        $this->assertArrayHasKey('createdAt', $data);
        $this->assertNotNull($data['createdAt']);
        $this->assertCount(7, $data);
    }

    /**
     * Preparar una auditoría de edición de noticias.
     */
    public function test_prepare_creates_news_update_audit_data(): void
    {
        $logger = new AuditLogger();

        $data = $logger->prepare(
            'updated',
            'news',
            'noticia-prueba',
            'administrador-prueba',
            'admin',
            [
                'title',
                'content',
                'summary',
            ]
        );

        $this->assertIsArray($data);

        $this->assertSame('updated', $data['action']);
        $this->assertSame('news', $data['resourceType']);
        $this->assertSame('noticia-prueba', $data['resourceId']);
        $this->assertSame('administrador-prueba', $data['actorId']);
        $this->assertSame('admin', $data['actorRole']);

        $this->assertSame(
            ['title', 'content', 'summary'],
            $data['changedFields']
        );

        $this->assertArrayHasKey('createdAt', $data);
    }

    /**
     * Preparar registros para cambios de estado.
     */
    public function test_prepare_creates_status_audit_data(): void
    {
        $logger = new AuditLogger();

        $published = $logger->prepare(
            'published',
            'events',
            'evento-prueba',
            'usuario-prueba',
            'seller',
            ['status']
        );

        $unpublished = $logger->prepare(
            'unpublished',
            'news',
            'noticia-prueba',
            'usuario-prueba',
            'seller',
            ['status']
        );

        $this->assertIsArray($published);
        $this->assertIsArray($unpublished);

        $this->assertSame(
            'published',
            $published['action']
        );

        $this->assertSame(
            'unpublished',
            $unpublished['action']
        );

        $this->assertSame(
            ['status'],
            $published['changedFields']
        );

        $this->assertSame(
            ['status'],
            $unpublished['changedFields']
        );
    }

    /**
     * Rechazar datos inválidos al preparar auditorías.
     */
    public function test_prepare_rejects_invalid_data(): void
    {
        $logger = new AuditLogger();

        // No admitir recursos desconocidos.
        $this->assertNull($logger->prepare(
            'created',
            'payments',
            'pago-prueba',
            'usuario-prueba',
            'seller'
        ));

        // No admitir acciones desconocidas.
        $this->assertNull($logger->prepare(
            'deleted',
            'events',
            'evento-prueba',
            'usuario-prueba',
            'admin'
        ));

        // No admitir identificadores vacíos.
        $this->assertNull($logger->prepare(
            'updated',
            'events',
            '',
            'usuario-prueba',
            'seller'
        ));

        $this->assertNull($logger->prepare(
            'updated',
            'events',
            'evento-prueba',
            '  ',
            'seller'
        ));

        // No admitir roles sin autorización.
        $this->assertNull($logger->prepare(
            'updated',
            'news',
            'noticia-prueba',
            'usuario-prueba',
            'customer'
        ));
    }

    /**
     * Filtrar campos sensibles al preparar una auditoría.
     */
    public function test_prepare_filters_sensitive_fields(): void
    {
        $logger = new AuditLogger();

        $data = $logger->prepare(
            'updated',
            'events',
            'evento-prueba',
            'usuario-prueba',
            'admin',
            [
                'name',
                'password',
                'date',
                'token',
                'venue',
                'name',
                'secret',
            ]
        );

        $this->assertIsArray($data);

        $this->assertSame(
            ['name', 'date', 'venue'],
            $data['changedFields']
        );

        $this->assertNotContains(
            'password',
            $data['changedFields']
        );

        $this->assertNotContains(
            'token',
            $data['changedFields']
        );

        $this->assertNotContains(
            'secret',
            $data['changedFields']
        );
    }

    /**
     * Preparar Firestore para una auditoría exitosa.
     */
    private function mockSuccessfulAudit(
        array $expected
    ): FirestoreClient {
        $document = Mockery::mock(
            DocumentReference::class
        );

        $document->shouldReceive('set')
            ->once()
            ->with(Mockery::on(
                function ($data) use ($expected) {
                    if (!is_array($data)) {
                        return false;
                    }

                    // Verificar la fecha sin guardar datos sensibles.
                    if (!array_key_exists('createdAt', $data)) {
                        return false;
                    }

                    $timestamp = $data['createdAt'];

                    unset($data['createdAt']);

                    return $data === $expected
                        && $timestamp !== null;
                }
            ));

        $collection = Mockery::mock(
            CollectionReference::class
        );

        $collection->shouldReceive('newDocument')
            ->once()
            ->andReturn($document);

        $firestore = Mockery::mock(
            FirestoreClient::class
        );

        $firestore->shouldReceive('collection')
            ->once()
            ->with('audit_logs')
            ->andReturn($collection);

        return $firestore;
    }

    /**
     * Verificar que Firestore no se utilice con datos inválidos.
     */
    private function mockUnusedFirestore(): FirestoreClient
    {
        $firestore = Mockery::mock(
            FirestoreClient::class
        );

        $firestore->shouldNotReceive('collection');

        return $firestore;
    }
}

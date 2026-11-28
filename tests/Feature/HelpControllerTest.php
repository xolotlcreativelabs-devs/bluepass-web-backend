<?php

namespace Tests\Feature;

use Tests\TestCase;

class HelpControllerTest extends TestCase
{
    public function test_help_content_is_public(): void
    {
        $response = $this->getJson('/api/help');

        $response->assertOk();

        $response->assertJsonStructure([
            'message',
            'categories' => [
                '*' => [
                    'id',
                    'title',
                    'questions' => [
                        '*' => [
                            'id',
                            'question',
                            'answer',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_help_has_expected_categories(): void
    {
        $response = $this->getJson('/api/help');

        $response->assertOk();

        $response->assertJsonCount(4, 'categories');

        $response->assertJsonPath(
            'categories.0.id',
            'purchases'
        );

        $response->assertJsonPath(
            'categories.1.id',
            'tickets'
        );

        $response->assertJsonPath(
            'categories.2.id',
            'account'
        );

        $response->assertJsonPath(
            'categories.3.id',
            'support'
        );
    }

    public function test_help_questions_have_content(): void
    {
        $response = $this->getJson('/api/help');

        $response->assertOk();

        $categories = $response->json('categories');

        $this->assertIsArray($categories);

        foreach ($categories as $category) {
            $this->assertNotEmpty($category['questions']);

            foreach ($category['questions'] as $question) {
                $this->assertNotEmpty($question['id']);
                $this->assertNotEmpty($question['question']);
                $this->assertNotEmpty($question['answer']);
            }
        }
    }

    public function test_help_rejects_unexpected_parameters(): void
    {
        $response = $this->getJson(
            '/api/help?userId=otro_usuario'
        );

        $response->assertStatus(422);

        $response->assertJsonStructure([
            'message',
        ]);
    }

    public function test_help_does_not_expose_private_information(): void
    {
        $response = $this->getJson('/api/help');

        $response->assertOk();

        $response->assertJsonMissingPath('userId');
        $response->assertJsonMissingPath('email');
        $response->assertJsonMissingPath('token');
    }
}

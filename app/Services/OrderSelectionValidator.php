<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class OrderSelectionValidator
{
    private const MAX_TICKETS = 10;

    /**
     * Validar la seleccion de boletos para un pedido.
     *
     * Aceptar compras tradicionales y multiples.
     */
    public function validate(array $input): array
    {
        if (array_key_exists('items', $input)) {
            return $this->validateMultiple($input);
        }

        return $this->validateSingle($input);
    }

    /**
     * Validar una compra de una sola categoria.
     */
    private function validateSingle(array $input): array
    {
        $allowedFields = [
            'reservationId',
            'ticketTypeId',
            'quantity',
        ];

        $validator = Validator::make($input, [
            'reservationId' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_-]{1,128}$/D',
            ],
            'ticketTypeId' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_-]{1,128}$/D',
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:' . self::MAX_TICKETS,
            ],
        ]);

        // No permitir precios ni datos adicionales.
        $validator->after(
            function ($validator) use (
                $input,
                $allowedFields
            ): void {
                $unexpectedFields = array_diff(
                    array_keys($input),
                    $allowedFields
                );

                if ($unexpectedFields !== []) {
                    $validator->errors()->add(
                        'fields',
                        implode(', ', $unexpectedFields)
                    );
                }
            }
        );

        $data = $validator->validate();

        return [
            'reservationId' => $data['reservationId'],
            'ticketTypeId' => $data['ticketTypeId'],
            'quantity' => (int) $data['quantity'],
        ];
    }

    /**
     * Validar una compra con varias categorias.
     */
    private function validateMultiple(array $input): array
    {
        $allowedFields = [
            'reservationId',
            'items',
        ];

        $allowedItemFields = [
            'ticketTypeId',
            'quantity',
        ];

        $validator = Validator::make($input, [
            'reservationId' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_-]{1,128}$/D',
            ],
            'items' => [
                'required',
                'array',
                'list',
                'min:1',
                'max:' . self::MAX_TICKETS,
            ],
            'items.*' => [
                'required',
                'array',
            ],
            'items.*.ticketTypeId' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_-]{1,128}$/D',
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:' . self::MAX_TICKETS,
            ],
        ]);

        $validator->after(
            function ($validator) use (
                $input,
                $allowedFields,
                $allowedItemFields
            ): void {
                // Rechazar campos adicionales del pedido.
                $unexpectedFields = array_diff(
                    array_keys($input),
                    $allowedFields
                );

                if ($unexpectedFields !== []) {
                    $validator->errors()->add(
                        'fields',
                        implode(', ', $unexpectedFields)
                    );
                }

                $items = $input['items'] ?? null;

                if (!is_array($items) || !array_is_list($items)) {
                    return;
                }

                $usedTypes = [];
                $total = 0;

                foreach ($items as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    // No aceptar precios enviados por el cliente.
                    $unexpectedItemFields = array_diff(
                        array_keys($item),
                        $allowedItemFields
                    );

                    if ($unexpectedItemFields !== []) {
                        $validator->errors()->add(
                            "items.$index",
                            'El boleto contiene campos no permitidos.'
                        );
                    }

                    $typeId = $item['ticketTypeId'] ?? null;

                    if (is_string($typeId)) {
                        if (isset($usedTypes[$typeId])) {
                            $validator->errors()->add(
                                "items.$index.ticketTypeId",
                                'No puedes repetir una categoria.'
                            );
                        }

                        $usedTypes[$typeId] = true;
                    }

                    $quantity = $item['quantity'] ?? null;

                    if (
                        is_int($quantity) ||
                        (
                            is_string($quantity) &&
                            ctype_digit($quantity) &&
                            strlen($quantity) <= 3
                        )
                    ) {
                        $total += (int) $quantity;
                    }
                }

                if ($total > self::MAX_TICKETS) {
                    $validator->errors()->add(
                        'items',
                        'No puedes seleccionar mas de 10 boletos.'
                    );
                }
            }
        );

        $data = $validator->validate();

        $items = [];
        $totalQuantity = 0;

        foreach ($data['items'] as $item) {
            $quantity = (int) $item['quantity'];

            $items[] = [
                'ticketTypeId' => $item['ticketTypeId'],
                'quantity' => $quantity,
            ];

            $totalQuantity += $quantity;
        }

        // Normalizar el orden de las categorias.
        usort(
            $items,
            static fn (array $first, array $second): int =>
                strcmp(
                    $first['ticketTypeId'],
                    $second['ticketTypeId']
                )
        );

        return [
            'reservationId' => $data['reservationId'],
            'items' => $items,
            'quantity' => $totalQuantity,
        ];
    }
}

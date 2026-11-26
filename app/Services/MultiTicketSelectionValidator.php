<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class MultiTicketSelectionValidator
{
    private const MAX_TICKETS = 10;

    private const MAX_TYPES = 10;

    /**
     * Validar varios tipos de boletos en una seleccion.
     */
    public function validate(array $input): array
    {
        $validator = Validator::make($input, [
            'items' => [
                'required',
                'array',
                'min:1',
                'max:' . self::MAX_TYPES,
            ],
            'items.*' => [
                'required',
                'array:ticketTypeId,quantity',
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

        $validator->after(function ($validator) use ($input): void {
            // No aceptar precios, importes ni datos del comprador.
            $unexpectedFields = array_diff(
                array_keys($input),
                ['items']
            );

            if ($unexpectedFields !== []) {
                $validator->errors()->add(
                    'fields',
                    'La seleccion contiene campos no autorizados.'
                );
            }

            $items = $input['items'] ?? null;

            if (!is_array($items)) {
                return;
            }

            if (!array_is_list($items)) {
                $validator->errors()->add(
                    'items',
                    'Los boletos deben enviarse como una lista.'
                );

                return;
            }

            $usedTypes = [];
            $total = 0;

            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $typeId = $item['ticketTypeId'] ?? null;
                $quantity = $item['quantity'] ?? null;

                if (
                    is_string($typeId) &&
                    preg_match(
                        '/^[A-Za-z0-9_-]{1,128}$/D',
                        $typeId
                    ) === 1
                ) {
                    if (isset($usedTypes[$typeId])) {
                        $validator->errors()->add(
                            'items.' . $index . '.ticketTypeId',
                            'No puedes repetir un tipo de boleto.'
                        );
                    }

                    $usedTypes[$typeId] = true;
                }

                if (
                    is_int($quantity) ||
                    (
                        is_string($quantity) &&
                        ctype_digit($quantity)
                    )
                ) {
                    $total += (int) $quantity;
                }
            }

            if ($total > self::MAX_TICKETS) {
                $validator->errors()->add(
                    'items',
                    'No puedes seleccionar mas de 10 boletos en total.'
                );
            }
        });

        $data = $validator->validate();

        $items = [];

        foreach ($data['items'] as $item) {
            $items[] = [
                'ticketTypeId' => $item['ticketTypeId'],
                'quantity' => (int) $item['quantity'],
            ];
        }

        // Mantener un orden estable para comparar selecciones.
        usort(
            $items,
            static fn (array $a, array $b): int =>
                strcmp(
                    $a['ticketTypeId'],
                    $b['ticketTypeId']
                )
        );

        return [
            'items' => $items,
            'quantity' => array_sum(
                array_column($items, 'quantity')
            ),
        ];
    }
}

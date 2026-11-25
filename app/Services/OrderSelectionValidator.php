<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class OrderSelectionValidator
{
    private const MAX_TICKETS = 10;

    /**
     * Validar los boletos seleccionados para un pedido.
     */
    public function validate(array $input): array
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

        // No permitir precios, totales ni otros datos del comprador.
        $validator->after(function ($validator) use (
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
                    'Se enviaron campos no autorizados: ' .
                    implode(', ', $unexpectedFields)
                );
            }
        });

        $data = $validator->validate();

        return [
            'reservationId' => $data['reservationId'],
            'ticketTypeId' => $data['ticketTypeId'],
            'quantity' => (int) $data['quantity'],
        ];
    }
}

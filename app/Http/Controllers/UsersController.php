<?php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;

class UsersController extends Controller
{
    public function information(Factory $validator, string $email): JsonResponse
    {
        $validator = $validator->make(compact('email'), ['email' => ['required', 'email']]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $employees = config('api.employees');

        abort_if(!array_key_exists($email, $employees), 409, 'Not Found.');

        return new JsonResponse(
            [
                'message' => 'Success.',
                'data' => [
                    'email' => $email,
                    'isEmployee' => $employees[$email],
                ],
            ]
        );
    }
}

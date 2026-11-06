<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateFirstAdmin extends Command
{
    protected $signature = 'rustik:create-first-admin';

    protected $description = 'Crea el primer administrador mediante preguntas interactivas';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Este comando requiere una terminal interactiva.');

            return self::FAILURE;
        }

        if (User::where('role', Role::Administrator->value)->exists()) {
            $this->error('Ya existe un administrador. Use la API para administrar usuarios.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->ask('Nombre'),
            'email' => $this->ask('Correo'),
            'password' => $this->secret('Contrase?a (m?nimo 12 caracteres)'),
            'password_confirmation' => $this->secret('Confirme la contrase?a'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::min(12), 'max:1024'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Acquire only after the interactive prompts to avoid a lock expiring while typing.
        $lock = Cache::lock('rustik:create-first-admin', 120);
        if (! $lock->get()) {
            $this->error('Ya hay una creaci?n de administrador en curso.');

            return self::FAILURE;
        }
        try {
            if (User::where('role', Role::Administrator->value)->exists()) {
                $this->error('Ya existe un administrador. Use la API para administrar usuarios.');

                return self::FAILURE;
            }
            User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role' => Role::Administrator, 'is_active' => true]);
            $this->info('Administrador creado.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}

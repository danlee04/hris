<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * The first way in.
 *
 * Public registration is closed, so a fresh install has no way to produce an
 * account and nobody can sign in. This is that way, and it is deliberately a
 * console command rather than a seeder: a seeded administrator means a
 * password committed to version control, the same on every install, and
 * nothing to stop it surviving into production.
 */
class CreateAdmin extends Command
{
    // The password is not an option. Anything typed on a command line lands in
    // the shell history and in the process list, where every other account on
    // the server can read it.
    protected $signature = 'hris:create-admin
                            {--name= : The administrator\'s name}
                            {--email= : The address they sign in with}';

    protected $description = 'Create an administrator account';

    public function handle(): int
    {
        if (! Role::where('name', 'admin')->where('guard_name', 'web')->exists()) {
            // Creating the role here would produce an administrator with no
            // permissions — an account that signs in and can do nothing, which
            // is harder to diagnose than a refusal.
            $this->error('The admin role does not exist. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm the password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            $this->newLine();

            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            // The person at the console chose this password themselves. There
            // is nobody to hide it from and nothing to replace.
            'must_change_password' => false,
        ]);

        // There is no mail server on a hospital LAN. An unverified account
        // never gets past the `verified` middleware, so the first
        // administrator would be locked out by the very thing meant to let
        // them in.
        $user->forceFill(['email_verified_at' => now()])->save();

        $user->assignRole('admin');

        $this->newLine();
        $this->info("Administrator [{$email}] created.");

        // Dan's rule: the admin account, the HR account and an employee's own
        // account are three different people. Linking this one to an employee
        // record would collapse that distinction on the first install.
        $this->line('This account is not linked to an employee record. Issue employee logins from Employees → Issue a login.');

        return self::SUCCESS;
    }
}

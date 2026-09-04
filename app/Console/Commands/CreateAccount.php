<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * The way an account is made.
 *
 * Public registration is closed, so a fresh install has no way to produce one
 * and nobody can sign in. It is deliberately a console command rather than a
 * seeder: a seeded account means a password committed to version control, the
 * same on every install, and nothing to stop it surviving into production.
 *
 * It is also the only way to make an HR account. Issue a login, on the
 * employee list, grants the employee role and nothing else — by design, since
 * the person doing the issuing should not be able to hand out their own
 * powers from a dropdown.
 */
class CreateAccount extends Command
{
    // The password is not an option. Anything typed on a command line lands in
    // the shell history and in the process list, where every other account on
    // the server can read it.
    protected $signature = 'hris:create-account
                            {--name= : The person\'s name}
                            {--email= : The address they sign in with}
                            {--role=admin : admin or hr}';

    protected $description = 'Create an administrator or HR account';

    public function handle(): int
    {
        $role = $this->option('role');

        // Not any role. The employee role is handed out by Issue a login,
        // which ties the account to a person; one made here would belong to
        // nobody.
        if (! in_array($role, ['admin', 'hr'], true)) {
            $this->error("[{$role}] is not a role this command creates. Use admin or hr.");

            return self::FAILURE;
        }

        if (! Role::where('name', $role)->where('guard_name', 'web')->exists()) {
            // Creating the role here would produce an account with no
            // permissions — one that signs in and can do nothing, which is
            // harder to diagnose than a refusal.
            $this->error("The {$role} role does not exist. Run `php artisan db:seed` first.");

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

        $user->assignRole($role);

        $this->newLine();
        $this->info("Account [{$email}] created with the {$role} role.");

        // Dan's rule: the admin account, the HR account and an employee's own
        // account are three different people. Linking this one to an employee
        // record would collapse that distinction on the first install.
        $this->line('This account is not linked to an employee record. Issue employee logins from Employees → Issue a login.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Lets the rest of the hospital in.
 *
 * Everything built so far — the PDS, the leave module, CS Form 6 — was reachable
 * by four accounts. Issuing the other hundred and thirty one at a time through
 * the screen means typing an address and inventing a password a hundred and
 * thirty times, and it is why nobody's PDS is filled in.
 *
 * **The temporary password is shared, and that is a real exposure.** Every
 * account created here is survivable only because `must_change_password` is
 * enforced: such an account reaches the change-password page and nothing else,
 * so no PDS can be read with it. What it cannot stop is somebody on the LAN who
 * knows the naming pattern signing in as a colleague who has not signed in yet,
 * choosing a password, and keeping the account. The defence is the speed the
 * slips are handed out, not anything in this file — so issue them in the
 * batches you can distribute the same day, not all at once weeks ahead.
 */
class IssueLogins extends Command
{
    protected $signature = 'employees:issue-logins
                            {--domain=gmail.com : The part after the @}
                            {--password=password : The temporary password, which everybody must replace}
                            {--slips= : Write the slips to this CSV instead of only listing them}
                            {--force : Write without asking for confirmation}';

    protected $description = 'Create a login for every active employee who has none';

    public function handle(): int
    {
        $employees = Employee::where('is_active', true)
            ->whereNull('user_id')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        if ($employees->isEmpty()) {
            $this->info('Everybody already has a login. Nothing to do.');

            return self::SUCCESS;
        }

        $password = (string) $this->option('password');
        $slips = $this->plan($employees);

        $this->newLine();
        $this->table(['Employee No.', 'Name', 'Username'],
            collect($slips)->map(fn ($s) => [$s['number'], $s['name'], $s['email']])->all());

        $this->newLine();
        $this->warn("Every one of these is created with the password [{$password}] and must replace it "
            .'before reaching anything. Hand the slips out the same day you run this.');

        if (! $this->option('force') && ! $this->confirm(
            sprintf('Issue %d %s?', count($slips), Str::plural('login', count($slips))), true
        )) {
            $this->line('Cancelled. Nothing was written.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($slips));
        $bar->start();

        // One transaction: a half-issued run leaves the rest of the hospital
        // out with no way to tell from the outside who was reached.
        DB::transaction(function () use ($slips, $password, $bar) {
            foreach ($slips as $slip) {
                $user = User::create([
                    'name' => $slip['name'],
                    'email' => $slip['email'],
                    'password' => Hash::make($password),
                    'must_change_password' => true,
                ]);

                $user->assignRole('employee');
                $slip['employee']->update(['user_id' => $user->id]);

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($path = $this->option('slips')) {
            $this->writeSlips($path, $slips, $password);

            $this->line("Slips written to [{$path}].");
            $this->warn('That file lists every username beside the password that opens it, in clear '
                .'text. Print it, hand it out, then delete it — and keep it out of the repository.');
        }

        $this->info(sprintf('%d logins issued.', count($slips)));

        return self::SUCCESS;
    }

    /**
     * Works out every address before writing anything, so a collision is
     * settled against the whole batch rather than only against the database.
     *
     * @param  Collection<int, Employee>  $employees
     * @return list<array{employee: Employee, number: string, name: string, email: string}>
     */
    private function plan($employees): array
    {
        $domain = ltrim((string) $this->option('domain'), '@');
        $taken = User::pluck('email')->map(fn ($e) => mb_strtolower($e))->flip();

        $slips = [];

        foreach ($employees as $employee) {
            $local = $this->localPart($employee);
            $email = "{$local}@{$domain}";

            // In a hospital of 134 a second Juan Cruz is not a corner case, and
            // without this the unique index stops the whole run on his row.
            $suffix = 2;

            while ($taken->has(mb_strtolower($email))) {
                $email = "{$local}{$suffix}@{$domain}";
                $suffix++;
            }

            $taken[mb_strtolower($email)] = true;

            $slips[] = [
                'employee' => $employee,
                'number' => (string) $employee->employee_number,
                'name' => $employee->fullName(),
                'email' => $email,
            ];
        }

        return $slips;
    }

    /**
     * First name and last name, run together.
     *
     * Everything an address cannot carry comes out: spaces in "Mary Jane",
     * the apostrophe in O'Brien, the hyphen in a married double surname, and
     * the accent in Concepción — transliterated rather than dropped, so the
     * name is still recognisable to the person reading their own slip.
     */
    private function localPart(Employee $employee): string
    {
        $name = Str::ascii("{$employee->first_name}{$employee->last_name}");

        return mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'employee');
    }

    /**
     * @param  list<array{number: string, name: string, email: string}>  $slips
     */
    private function writeSlips(string $path, array $slips, string $password): void
    {
        $handle = fopen($path, 'w');

        fputcsv($handle, ['Employee No.', 'Name', 'Username', 'Temporary password'], escape: '');

        foreach ($slips as $slip) {
            fputcsv($handle, [$slip['number'], $slip['name'], $slip['email'], $password], escape: '');
        }

        fclose($handle);
    }
}

<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Adds many customers at once from a CSV (Excel → Save as CSV). All or
 * nothing: every row is checked first, and if any row has a problem nothing
 * is imported, so a half-imported list never has to be untangled.
 */
class ClientImport
{
    public const MAX_ROWS = 2000;

    /** In template order. */
    public const COLUMNS = ['name', 'email', 'phone', 'address', 'plan', 'service', 'username', 'password', 'next_due_date', 'cutoff'];

    private const REQUIRED = ['name', 'email', 'plan', 'username', 'password', 'next_due_date'];

    /** Dates as Excel tends to save them, besides 2026-11-15. */
    private const DATE_FORMATS = ['Y-m-d', 'm/d/Y', 'n/j/Y', 'Y/m/d'];

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, message: string}>}
     */
    public function read(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '\\');
        if (! $header) {
            return ['rows' => [], 'errors' => [['line' => 1, 'message' => 'The file is empty.']]];
        }

        // Excel puts a byte-order mark before the first column name.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($column) => Str::snake(trim(Str::lower((string) $column))), $header);

        $missing = array_diff(self::REQUIRED, $header);
        if ($missing) {
            fclose($handle);

            return ['rows' => [], 'errors' => [['line' => 1, 'message' => 'Missing column(s): '.implode(', ', $missing).'. Start from the template.']]];
        }

        $plans = $this->plansByName();
        $rows = [];
        $errors = [];
        $seenEmails = [];
        $seenUsernames = [];
        $line = 1;

        while (($values = fgetcsv($handle, escape: '\\')) !== false) {
            $line++;
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            if (count($rows) + count($errors) >= self::MAX_ROWS) {
                $errors[] = ['line' => $line, 'message' => 'More than '.self::MAX_ROWS.' customers: split the file and import it in parts.'];
                break;
            }

            $cell = fn (string $column): string => trim((string) ($values[array_search($column, $header, true)] ?? ''));
            $has = fn (string $column): bool => in_array($column, $header, true);
            $problems = [];

            $plan = $plans[Str::lower($cell('plan'))] ?? null;
            if (! $plan) {
                $problems[] = "unknown plan \"{$cell('plan')}\" (use a plan name or MikroTik profile from the Plans page)";
            }

            $cutoff = Str::lower($has('cutoff') ? $cell('cutoff') : '');
            $autoSuspend = match ($cutoff) {
                '', 'automatic', 'auto' => true,
                'manual' => false,
                default => null,
            };
            if ($autoSuspend === null) {
                $problems[] = "cutoff must be \"automatic\" or \"manual\", not \"{$cell('cutoff')}\"";
            }

            $dueDate = $this->parseDate($cell('next_due_date'));
            if (! $dueDate) {
                $problems[] = "next_due_date \"{$cell('next_due_date')}\" isn't a date (use YYYY-MM-DD, e.g. 2026-11-15)";
            }

            $data = [
                'name' => $cell('name'),
                'email' => Str::lower($cell('email')),
                'phone' => $has('phone') ? ($cell('phone') ?: null) : null,
                'address' => $has('address') ? ($cell('address') ?: null) : null,
                'plan_id' => $plan?->id,
                'service_type' => Str::lower($has('service') ? $cell('service') : '') ?: 'pppoe',
                'username' => $cell('username'),
                'service_password' => $cell('password'),
                'next_due_date' => $dueDate ?? $cell('next_due_date'),
                'auto_suspend' => $autoSuspend ?? true,
            ];

            $validator = Validator::make($data, ClientOnboarding::rules())->setAttributeNames([
                'service_type' => 'service',
                'service_password' => 'password',
                'next_due_date' => 'next due date',
                'auto_suspend' => 'cutoff',
            ]);
            foreach ($validator->errors()->all() as $message) {
                $problems[] = Str::lcfirst(rtrim($message, '.'));
            }

            if ($data['email'] !== '' && isset($seenEmails[$data['email']])) {
                $problems[] = "same email as line {$seenEmails[$data['email']]}";
            }
            if ($data['username'] !== '' && isset($seenUsernames[Str::lower($data['username'])])) {
                $problems[] = "same username as line {$seenUsernames[Str::lower($data['username'])]}";
            }
            $seenEmails[$data['email']] ??= $line;
            $seenUsernames[Str::lower($data['username'])] ??= $line;

            if ($problems) {
                $errors[] = ['line' => $line, 'message' => Str::ucfirst(implode('; ', $problems)).'.'];
            } else {
                $rows[] = $data;
            }
        }

        fclose($handle);

        return ['rows' => $errors ? [] : $rows, 'errors' => $errors];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  from read(), with no errors
     * @return list<Subscription>
     */
    public function import(array $rows, ClientOnboarding $onboarding): array
    {
        return DB::transaction(fn () => array_map(fn (array $row) => $onboarding->open($row), $rows));
    }

    /** @return array<string, Plan> active plans by lowercased name and MikroTik profile */
    private function plansByName(): array
    {
        $plans = [];
        foreach (Plan::where('is_active', true)->get() as $plan) {
            $plans[Str::lower($plan->mikrotik_profile)] ??= $plan;
            $plans[Str::lower($plan->name)] = $plan;
        }

        return $plans;
    }

    private function parseDate(string $value): ?string
    {
        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($date && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }
}

<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentCollector;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\Payments\DirectMoneyWriteException;
use App\Models\Concerns\GuardsMoneyWrites;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "Money is written in one place" — enforced, not merely agreed. Deliberately
 * the same two layers as InventoryWritePathTest, because it is the same rule
 * and a second shape for it would be one more thing to learn:
 *
 * 1. A runtime guard on the models, which catches Eloquent saves and deletes
 *    from anywhere, including a Filament action nobody has written yet.
 * 2. A source scan, because query-builder writes fire no model events and so
 *    slip past the runtime guard entirely.
 *
 * The invoice tables are here for a sharper reason than the payments one: the
 * gapless numbering guarantee is a single locked transaction in InvoiceIssuer,
 * and a second place that takes a number is a second place that can hand out
 * one that has already been used.
 */
class MoneyWritePathTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables that may only be written from the one write path.
     *
     * @var array<int, string>
     */
    private const MONEY_TABLES = [
        'payments',
        'invoices',
        'invoice_sequences',
    ];

    /**
     * Models whose writes are guarded at runtime.
     *
     * @var array<int, class-string>
     */
    private const MONEY_MODELS = [
        Payment::class,
        Invoice::class,
        InvoiceSequence::class,
    ];

    /**
     * Where money writes are legitimate: the write path itself, and the
     * migrations that create the tables in the first place.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PATHS = [
        'app/Services/Payments',
        'database/migrations',
    ];

    public function test_creating_a_payment_outside_the_recorder_is_refused(): void
    {
        $this->expectException(DirectMoneyWriteException::class);

        Payment::create([
            'reservation_id' => 1,
            'amount' => 500,
            'currency' => 'NAD',
            'received_at' => now(),
            'method' => PaymentMethod::Cash,
            'collected_by' => PaymentCollector::Partner,
            'status' => PaymentStatus::Cleared,
        ]);
    }

    public function test_every_money_model_is_guarded(): void
    {
        foreach (self::MONEY_MODELS as $model) {
            $this->assertContains(
                GuardsMoneyWrites::class,
                class_uses_recursive($model),
                "[{$model}] holds money but is not guarded — add the GuardsMoneyWrites trait."
            );
        }
    }

    public function test_nothing_outside_the_write_path_writes_the_money_tables(): void
    {
        $offences = [];

        foreach ($this->sourceFiles() as $path) {
            $relative = str_replace(base_path().'/', '', $path);

            if ($this->isAllowed($relative)) {
                continue;
            }

            $contents = File::get($path);

            foreach (self::MONEY_TABLES as $table) {
                // A query-builder write fires no model events, so the runtime
                // guard cannot see it. Reaching for the table by name outside
                // the write path is the signature of exactly that.
                if (preg_match('/DB::table\(\s*[\'"]'.preg_quote($table, '/').'[\'"]/', $contents) === 1) {
                    $offences[] = "{$relative} builds a query against [{$table}] directly.";
                }
            }

            foreach (self::MONEY_MODELS as $model) {
                $short = class_basename($model);
                $writers = ['create', 'insert', 'insertOrIgnore', 'updateOrCreate', 'firstOrCreate', 'upsert', 'destroy'];

                foreach ($writers as $writer) {
                    if (preg_match('/\b'.$short.'::'.$writer.'\(/', $contents) === 1) {
                        $offences[] = "{$relative} calls {$short}::{$writer}() directly.";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "Money is written from App\\Services\\Payments and nowhere else.\n"
            .'Add the operation to the writer that owns the table instead:'."\n - ".implode("\n - ", $offences)
        );
    }

    /**
     * @return array<int, string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach ([app_path(), database_path()] as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getRealPath();
                }
            }
        }

        return $files;
    }

    private function isAllowed(string $relativePath): bool
    {
        foreach (self::ALLOWED_PATHS as $allowed) {
            if (str_starts_with($relativePath, $allowed)) {
                return true;
            }
        }

        return false;
    }
}

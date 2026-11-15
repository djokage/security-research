<?php
/*
 * CSV-to-importer reproduction harness for Krayin CRM v2.2.8.
 * It loads the real importer and storage classes from the uploaded source archive,
 * while substituting framework/database-facing dependencies with in-memory doubles.
 * It exercises Krayin's actual CSV source parser, AbstractImporter validation/batching, concrete
 * importer validation, concrete storage classes, and concrete importBatch mutation methods.
 * This is not a Laravel HTTP/database integration test.
 */

namespace Illuminate\Support {
    class Arr {
        public static function only(array $array, array $keys): array {
            return array_intersect_key($array, array_flip($keys));
        }
        public static function except(array $array, array $keys): array {
            return array_diff_key($array, array_flip($keys));
        }
        public static function pluck(array $array, string $key): array {
            $out = [];
            foreach ($array as $item) {
                if (is_array($item) && array_key_exists($key, $item)) $out[] = $item[$key];
                elseif (is_object($item) && isset($item->{$key})) $out[] = $item->{$key};
            }
            return $out;
        }
    }

    class Collection implements \IteratorAggregate, \Countable {
        private array $items;
        public function __construct($items = []) {
            if ($items instanceof self) $items = $items->toArray();
            elseif (is_string($items)) $items = [$items];
            elseif (!is_array($items)) $items = $items === null ? [] : [$items];
            $this->items = $items;
        }
        public function map(callable $callback): self {
            $mapped = [];
            foreach ($this->items as $key => $value) $mapped[$key] = $callback($value, $key);
            $this->items = $mapped;
            return $this;
        }
        public function each(callable $callback): self {
            foreach ($this->items as $key => $value) $callback($value, $key);
            return $this;
        }
        public function toArray(): array { return $this->items; }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }
        public function count(): int { return count($this->items); }
        public function whereIn(string $key, array $values): self {
            $this->items = array_filter($this->items, function ($item) use ($key, $values) {
                $value = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
                return in_array($value, $values, true);
            });
            return $this;
        }
        public function pluck(string $valueKey, ?string $keyKey = null): self {
            $out = [];
            foreach ($this->items as $item) {
                $value = is_array($item) ? ($item[$valueKey] ?? null) : ($item->{$valueKey} ?? null);
                if ($keyKey === null) $out[] = $value;
                else {
                    $key = is_array($item) ? ($item[$keyKey] ?? null) : ($item->{$keyKey} ?? null);
                    $out[$key] = $value;
                }
            }
            $this->items = $out;
            return $this;
        }
        public function all(): array { return $this->items; }
    }
}

namespace Illuminate\Support\Facades {
    class Event { public static function dispatch(...$args): void {} }
    class Validator {
        public static array $lastRules = [];
        public static function make(array $data, array $rules): FakeValidatorResult {
            self::$lastRules = $rules;
            return new FakeValidatorResult($data, $rules);
        }
    }
    class FakeValidatorResult {
        private array $failed = [];
        private array $messages = [];
        public function __construct(private array $data, private array $rules) { $this->run(); }
        private function read(array $data, string $path) {
            $value = $data;
            foreach (explode('.', $path) as $part) {
                if ($part === '*') return is_array($value) ? $value : [];
                $value = is_array($value) ? ($value[$part] ?? null) : null;
            }
            return $value;
        }
        private function addFailure(string $field, string $rule): void {
            $this->failed[$field][$rule] = [];
            $this->messages[$field][] = $field.' failed '.$rule;
        }
        private function run(): void {
            $exists = ['users'=>[11,22], 'persons'=>[51,52], 'lead_sources'=>[1], 'lead_types'=>[1], 'lead_pipelines'=>[1], 'lead_pipeline_stages'=>[1], 'organizations'=>[8,9]];
            foreach ($this->rules as $field => $ruleSet) {
                $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
                if (str_contains($field, '.*.')) {
                    [$base, $tail] = explode('.*.', $field, 2);
                    $rows = $this->data[$base] ?? [];
                    foreach (is_array($rows) ? $rows : [] as $row) {
                        $value = is_array($row) ? ($row[$tail] ?? null) : null;
                        $this->checkValue($field, $value, $rules, $exists);
                    }
                } else {
                    $this->checkValue($field, $this->data[$field] ?? null, $rules, $exists, array_key_exists($field, $this->data));
                }
            }
        }
        private function checkValue(string $field, $value, array $rules, array $exists, bool $present = true): void {
            $nullable = in_array('nullable', $rules, true);
            $sometimes = in_array('sometimes', $rules, true);
            if ($sometimes && !$present) return;
            if ($nullable && ($value === null || $value === '')) return;
            foreach ($rules as $rule) {
                if (str_starts_with($rule, 'required') && (!$present || $value === null || $value === '')) $this->addFailure($field, 'Required');
                elseif ($rule === 'numeric' && !is_numeric($value)) $this->addFailure($field, 'Numeric');
                elseif ($rule === 'array' && !is_array($value)) $this->addFailure($field, 'Array');
                elseif ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $this->addFailure($field, 'Email');
                elseif (str_starts_with($rule, 'exists:')) {
                    $table = explode(':', $rule, 2)[1];
                    $table = explode(',', $table)[0];
                    if (!in_array((int)$value, $exists[$table] ?? [], true)) $this->addFailure($field, 'Exists');
                } elseif (str_starts_with($rule, 'in:')) {
                    $options = explode(',', substr($rule, 3));
                    if (!in_array((string)$value, $options, true)) $this->addFailure($field, 'In');
                }
            }
        }
        public function fails(): bool { return !empty($this->failed); }
        public function failed(): array { return $this->failed; }
        public function errors(): object { return new class($this->messages) { public function __construct(private array $m) {} public function getMessages(): array { return $this->m; } }; }
    }
    class Storage {
        public static string $root = '';
        public static function disk(?string $name = null): object {
            return new class {
                public function path(string $path): string { return Storage::$root.'/'.$path; }
            };
        }
    }

    class Schema {
        public static function getColumnListing(string $table): array {
            return match ($table) {
                'leads' => ['id','title','description','lead_value','status','lost_reason','closed_at','user_id','person_id','lead_source_id','lead_type_id','lead_pipeline_id','lead_pipeline_stage_id','expected_close_date','created_at','updated_at'],
                'persons' => ['id','name','emails','contact_numbers','job_title','organization_id','user_id','unique_id','created_at','updated_at'],
                default => [],
            };
        }
    }
}

namespace Webkul\DataTransfer\Contracts {
    interface Import {}
    interface ImportBatch {}
}

namespace Webkul\DataTransfer\Helpers {
    class Import {
        public const STORAGE_DISK = 'local';
        public const ACTION_APPEND = 'append';
        public const ACTION_DELETE = 'delete';
        public const STATE_PROCESSED = 'processed';
    }
}

namespace Webkul\Attribute\Repositories {
    class AttributeRepository {
        public function findWhere(array $where): \Illuminate\Support\Collection { return new \Illuminate\Support\Collection([]); }
        public function findOneWhere(array $where) { return null; }
        public function scopeQuery(callable $callback): object { return new class { public function get(): \Illuminate\Support\Collection { return new \Illuminate\Support\Collection([]); } }; }
    }
    class AttributeValueRepository {
        public array $upserts = [];
        public function upsert(array $rows, string $key): void { $this->upserts[] = [$rows, $key]; }
    }
}

namespace Webkul\Attribute\Models {
    class AttributeValue { public static array $attributeTypeFields = ['text' => 'text_value']; }
}

namespace Webkul\DataTransfer\Repositories {
    class ImportBatchRepository {
        public array $updates = [];
        public array $batches = [];
        public function deleteWhere(array $conditions): void { $this->batches = []; }
        public function create(array $data): object {
            $batch = (object) array_merge(['id' => 700 + count($this->batches)], $data);
            $this->batches[] = $batch;
            return $batch;
        }
        public function update(array $data, int $id) { $this->updates[] = [$id, $data]; return (object) ['id' => $id]; }
    }
}

namespace Webkul\Lead\Repositories {
    class LeadRepository {
        public array $rows = [];
        public array $lastUpsert = [];
        public array $lastDelete = [];
        public function __construct(array $rows = []) {
            foreach ($rows as $row) $this->rows[$row['id']] = $row;
        }
        public function findWhereIn(string $column, array $values, array $select = []): array {
            $out = [];
            foreach ($this->rows as $row) {
                if (in_array($row[$column] ?? null, $values, true) || in_array((string)($row[$column] ?? ''), array_map('strval', $values), true)) {
                    $out[] = (object) ($select ? array_intersect_key($row, array_flip($select)) : $row);
                }
            }
            return $out;
        }
        public function all(array $select = []): array {
            return array_map(fn($r) => (object) ($select ? array_intersect_key($r, array_flip($select)) : $r), array_values($this->rows));
        }
        public function upsert(array $rows, string $uniqueBy): void {
            $this->lastUpsert = [$rows, $uniqueBy];
            foreach ($rows as $key => $row) {
                $id = $row['id'] ?? $key;
                if (isset($this->rows[$id])) $this->rows[$id] = array_merge($this->rows[$id], $row);
                else $this->rows[$id] = $row;
            }
        }
        public function insert(array $rows): void {
            foreach ($rows as $row) {
                $id = $row['id'] ?? (max(array_keys($this->rows ?: [0 => 0])) + 1);
                $this->rows[$id] = array_merge(['id' => $id], $row);
            }
        }
        public function deleteWhere(array $conditions): void {
            $this->lastDelete = $conditions;
            $ids = $conditions[0][2] ?? [];
            foreach ($ids as $id) if (is_int($id) || ctype_digit((string)$id)) unset($this->rows[(int)$id]);
        }
    }
    class ProductRepository {}
}

namespace Webkul\Contact\Repositories {
    class PersonQuery {
        public array $emails = [];
        public function where(callable $callback): self { $callback($this); return $this; }
        public function orWhereJsonContains(string $column, array $selector): self {
            if ($column === 'emails' && isset($selector['value'])) $this->emails[] = $selector['value'];
            return $this;
        }
    }

    class PersonRepository {
        public array $rows = [];
        public array $lastUpsert = [];
        public array $lastDelete = [];
        private array $scopeEmails = [];
        public function __construct(array $rows = []) {
            foreach ($rows as $row) $this->rows[$row['id']] = $row;
        }
        public function scopeQuery(callable $callback): self {
            $query = new PersonQuery;
            $callback($query);
            $this->scopeEmails = $query->emails;
            return $this;
        }
        public function all(array $select = []): \Illuminate\Support\Collection {
            $out = [];
            foreach ($this->rows as $row) {
                $has = false;
                foreach (($row['emails'] ?? []) as $email) {
                    $value = is_array($email) ? ($email['value'] ?? null) : $email;
                    if (in_array($value, $this->scopeEmails, true)) $has = true;
                }
                if ($has || $this->scopeEmails === []) {
                    $out[] = (object) ($select ? array_intersect_key($row, array_flip($select)) : $row);
                }
            }
            $this->scopeEmails = [];
            return new \Illuminate\Support\Collection($out);
        }
        public function upsert(array $rows, string $uniqueBy): void {
            $this->lastUpsert = [$rows, $uniqueBy];
            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                if ($id !== null && isset($this->rows[$id])) $this->rows[$id] = array_merge($this->rows[$id], $row);
                elseif ($id !== null) $this->rows[$id] = $row;
            }
        }
        public function insert(array $rows): void {
            foreach ($rows as $row) {
                $id = $row['id'] ?? (max(array_keys($this->rows ?: [0 => 0])) + 1);
                $this->rows[$id] = array_merge(['id' => $id], $row);
            }
        }
        public function deleteWhere(array $conditions): void {
            $this->lastDelete = $conditions;
            $ids = $conditions[0][2] ?? [];
            foreach ($ids as $id) unset($this->rows[(int)$id]);
        }
    }
}

namespace {
    function collect($items = []): \Illuminate\Support\Collection {
        return new \Illuminate\Support\Collection($items);
    }
    function trans($value, array $replace = []): string { return (string) $value; }
    class AuthGuardProbe {
        public function user() { return $GLOBALS['krayin_actor'] ?? null; }
        public function id() { return ($GLOBALS['krayin_actor']->id ?? null); }
        public function check(): bool { return isset($GLOBALS['krayin_actor']); }
    }
    class AuthProbe { public function guard($name): AuthGuardProbe { return new AuthGuardProbe; } }
    function auth(): AuthProbe { return new AuthProbe; }
    function now(): string { return '2026-11-15 00:00:00'; }

    $root = getenv('KRAYIN_ROOT');
    if (!$root) {
        foreach ([__DIR__.'/../laravel-crm-2.2.8', '/mnt/data/krayin-work/laravel-crm-2.2.8'] as $candidate) {
            if (is_file($candidate.'/packages/Webkul/Admin/src/Bouncer.php')) { $root = $candidate; break; }
        }
    }
    if (!$root || !is_file($root.'/packages/Webkul/Admin/src/Bouncer.php')) {
        fwrite(STDERR, "Set KRAYIN_ROOT to the extracted Krayin CRM v2.2.8 project directory.\n");
        exit(2);
    }
    require $root.'/packages/Webkul/Admin/src/Bouncer.php';
    require $root.'/packages/Webkul/DataTransfer/src/Helpers/Importers/AbstractImporter.php';
    require $root.'/packages/Webkul/DataTransfer/src/Helpers/Sources/AbstractSource.php';
    require $root.'/packages/Webkul/DataTransfer/src/Helpers/Sources/CSV.php';
    require $root.'/packages/Webkul/DataTransfer/src/Helpers/Importers/Leads/Storage.php';
    require $root.'/packages/Webkul/DataTransfer/src/Helpers/Importers/Leads/Importer.php';
    require $root.'/packages/Webkul/DataTransfer/src/Helpers/Importers/Persons/Storage.php';
    require $root.'/packages/Webkul/DataTransfer/src/Helpers/Importers/Persons/Importer.php';

    class ImportProbe implements \Webkul\DataTransfer\Contracts\Import {
        public int $id = 800;
        public function __construct(public string $action) {}
    }
    class ErrorProbe {
        private array $invalidRows = [];
        private int $errorsCount = 0;
        public function isRowInvalid(int $row): bool { return in_array($row, $this->invalidRows, true); }
        public function addErrorMessage(...$args): void {}
        public function addError(string $code, ?int $row = null, ?string $column = null, ?string $message = null): self {
            $this->errorsCount++; if ($row !== null) $this->invalidRows[] = $row; return $this;
        }
        public function addRowToSkip(?int $row): self { if ($row !== null) $this->invalidRows[] = $row; return $this; }
        public function getErrorsCount(): int { return $this->errorsCount; }
    }
    class ImportBatchProbe implements \Webkul\DataTransfer\Contracts\ImportBatch {
        public int $id = 700;
        public array $data = [];
        public object $import;
        public function __construct(array $data, string $action) {
            $this->data = $data;
            $this->import = (object) ['action' => $action];
        }
    }

    function expect(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException('FAIL: '.$message);
        echo 'PASS: '.$message."\n";
    }

    // Use the real Bouncer with a limited actor: user 11 has individual visibility.
    $GLOBALS['krayin_actor'] = (object) ['id' => 11, 'view_permission' => 'individual'];
    $authorizedUserIds = (new \Webkul\Admin\Bouncer)->getAuthorizedUserIds();
    expect($authorizedUserIds === [11], 'real Bouncer resolves individual data scope to actor user_id=11 only');

    // Confirm the route surface: authenticated admin middleware + independent ACL permissions.
    $acl = require $root.'/packages/Webkul/Admin/src/Config/acl.php';
    $aclByKey = [];
    foreach ($acl as $entry) if (isset($entry['key'])) $aclByKey[$entry['key']] = $entry;
    $createRoutes = $aclByKey['settings.automation.data_transfer.imports.create']['route'] ?? [];
    $importRoutes = $aclByKey['settings.automation.data_transfer.imports.import']['route'] ?? [];
    expect(in_array('admin.settings.data_transfer.imports.store', (array) $createRoutes, true), 'CSV upload route is independently permission-gated by the Data Transfer create ACL');
    expect(in_array('admin.settings.data_transfer.imports.validate', (array) $importRoutes, true) && in_array('admin.settings.data_transfer.imports.start', (array) $importRoutes, true), 'validation and execution routes are permission-gated by the Data Transfer import ACL');
    $providerText = file_get_contents($root.'/packages/Webkul/Admin/src/Providers/AdminServiceProvider.php');
    expect(str_contains($providerText, "Route::middleware(['web', 'admin_locale', 'user'])"), 'Admin routes, including Data Transfer, are behind authenticated user middleware');
    $settingsRoutes = file_get_contents($root.'/packages/Webkul/Admin/src/Routes/Admin/settings-routes.php');
    expect(str_contains($settingsRoutes, "Route::prefix('data-transfer')") && str_contains($settingsRoutes, "Route::controller(ImportController::class)->prefix('imports')"), 'real settings route file registers the import controller endpoints');
    $importController = file_get_contents($root.'/packages/Webkul/Admin/src/Http/Controllers/Settings/DataTransfer/ImportController.php');
    expect(!str_contains($importController, 'preventUnauthorizedAccess'), 'ImportController does not apply record-owner checks before validation or import execution');
    $userController = file_get_contents($root.'/packages/Webkul/Admin/src/Http/Controllers/Settings/UserController.php');
    expect(str_contains($userController, "'view_permission' => 'string|in:global,group,individual'") && str_contains($userController, 'preventUnauthorizedRoleAssignment') && str_contains($userController, 'preventUnauthorizedScopeAssignment'), 'user role permissions and individual/group/global data scope are configured as separate controls');

    // Verify the production importer/storage code does not invoke Bouncer data-scope filtering.
    $scopeFiles = [
        'packages/Webkul/DataTransfer/src/Helpers/Importers/Leads/Importer.php',
        'packages/Webkul/DataTransfer/src/Helpers/Importers/Leads/Storage.php',
        'packages/Webkul/DataTransfer/src/Helpers/Importers/Persons/Importer.php',
        'packages/Webkul/DataTransfer/src/Helpers/Importers/Persons/Storage.php',
    ];
    foreach ($scopeFiles as $relative) {
        $text = file_get_contents($root.'/'.$relative);
        expect(!preg_match('/bouncer\s*\(|scopeToAuthorizedUsers|preventUnauthorizedAccess/', $text), basename($relative).' contains no Bouncer/owner-scope enforcement');
    }

    // Temporary private-disk analogue used by the REAL Krayin CSV source class.
    $storageRoot = sys_get_temp_dir().'/krayin-v2.2.8-csv-repro-'.getmypid();
    if (!is_dir($storageRoot)) mkdir($storageRoot, 0700, true);
    \Illuminate\Support\Facades\Storage::$root = $storageRoot;
    if (!is_dir($storageRoot.'/imports')) mkdir($storageRoot.'/imports', 0700, true);

    $writeCsv = function (string $relative, array $headers, array $rows) use ($storageRoot): void {
        $path = $storageRoot.'/'.$relative;
        $handle = fopen($path, 'w');
        if (!$handle) throw new \RuntimeException('Could not create CSV fixture: '.$path);
        fputcsv($handle, $headers);
        foreach ($rows as $row) fputcsv($handle, $row);
        fclose($handle);
    };

    // ---------------- Lead update through the real CSV validation + batch + importer flow ----------------
    $leadRepo = new \Webkul\Lead\Repositories\LeadRepository([
        ['id'=>101,'title'=>'Victim Deal','description'=>'original description','lead_value'=>'100','status'=>1,'user_id'=>22,'person_id'=>51,'lead_source_id'=>1,'lead_type_id'=>1,'lead_pipeline_id'=>1,'lead_pipeline_stage_id'=>1],
        ['id'=>102,'title'=>'Actor Deal','description'=>'actor original','lead_value'=>'50','status'=>1,'user_id'=>11,'person_id'=>52,'lead_source_id'=>1,'lead_type_id'=>1,'lead_pipeline_id'=>1,'lead_pipeline_stage_id'=>1],
    ]);
    $leadBatchRepo = new \Webkul\DataTransfer\Repositories\ImportBatchRepository;
    $attrRepo = new \Webkul\Attribute\Repositories\AttributeRepository;
    $attrValueRepo = new \Webkul\Attribute\Repositories\AttributeValueRepository;
    expect(!in_array((int) $leadRepo->rows[101]['user_id'], $authorizedUserIds, true), 'victim lead owner user_id=22 is outside the actor individual scope');
    $leadStorage = new \Webkul\DataTransfer\Helpers\Importers\Leads\Storage($leadRepo);
    $leadImporter = new \Webkul\DataTransfer\Helpers\Importers\Leads\Importer(
        $leadBatchRepo, $leadRepo, new \Webkul\Lead\Repositories\ProductRepository,
        $attrRepo, $attrValueRepo, $leadStorage
    );
    $leadImporter->setImport(new ImportProbe(\Webkul\DataTransfer\Helpers\Import::ACTION_APPEND));
    $leadImporter->setErrorHelper(new ErrorProbe);
    $leadCsvHeaders = ['id','title','description','lead_value','status','user_id','person_id','lead_source_id','lead_type_id','lead_pipeline_id','lead_pipeline_stage_id'];
    $writeCsv('imports/foreign-lead-update.csv', $leadCsvHeaders, [[101,'Victim Deal','modified by restricted user','100','1','11','51','1','1','1','1']]);
    $leadImporter->setSource(new \Webkul\DataTransfer\Helpers\Sources\CSV('imports/foreign-lead-update.csv', ','));
    $leadImporter->validateData();
    expect($leadImporter->getProcessedRowsCount() === 1, 'real AbstractImporter CSV pipeline parses and validates one lead row');
    expect(count($leadBatchRepo->batches) === 1 && count($leadBatchRepo->batches[0]->data) === 1, 'lead CSV row is accepted into a production importer batch');
    expect(\Illuminate\Support\Facades\Validator::$lastRules['user_id'] === 'required|exists:users,id', 'lead row validation checks that the submitted owner exists, not that it is within actor scope');
    $leadBatch = new ImportBatchProbe($leadBatchRepo->batches[0]->data, \Webkul\DataTransfer\Helpers\Import::ACTION_APPEND);
    $leadImporter->importBatch($leadBatch);
    expect((int) $leadRepo->rows[101]['user_id'] === 11, 'CSV import reassigns victim-owned lead #101 from user 22 to actor user 11');
    expect($leadRepo->rows[101]['description'] === 'modified by restricted user', 'CSV import changes victim-owned lead data');
    expect($leadImporter->getUpdatedItemsCount() === 1, 'lead importer records one successful update');

    // ---------------- Person update through the real CSV validation + batch + importer flow ----------------
    $victimEmail = 'victim.contact@example.test';
    $personRepo = new \Webkul\Contact\Repositories\PersonRepository([
        ['id'=>202,'name'=>'Victim Contact','emails'=>[['label'=>'work','value'=>$victimEmail]],
         'contact_numbers'=>[['label'=>'work','value'=>'5550000222']], 'job_title'=>'Original title',
         'organization_id'=>8,'user_id'=>22,'unique_id'=>'22|8|'.$victimEmail.'|5550000222'],
    ]);
    expect(!in_array((int) $personRepo->rows[202]['user_id'], $authorizedUserIds, true), 'victim contact owner user_id=22 is outside the actor individual scope');
    $personBatchRepo = new \Webkul\DataTransfer\Repositories\ImportBatchRepository;
    $personStorage = new \Webkul\DataTransfer\Helpers\Importers\Persons\Storage($personRepo);
    $personImporter = new \Webkul\DataTransfer\Helpers\Importers\Persons\Importer(
        $personBatchRepo, $personRepo, $attrRepo, new \Webkul\Attribute\Repositories\AttributeValueRepository, $personStorage
    );
    $personImporter->setImport(new ImportProbe(\Webkul\DataTransfer\Helpers\Import::ACTION_APPEND));
    $personImporter->setErrorHelper(new ErrorProbe);
    $personCsvHeaders = ['name','emails','contact_numbers','job_title','organization_id','user_id'];
    $writeCsv('imports/foreign-person-update.csv', $personCsvHeaders, [[
        'Modified Contact', json_encode([['label'=>'work','value'=>$victimEmail]]),
        json_encode([['label'=>'work','value'=>'5550000999']]), 'Changed by CSV import', '8', '11'
    ]]);
    $personImporter->setSource(new \Webkul\DataTransfer\Helpers\Sources\CSV('imports/foreign-person-update.csv', ','));
    $personImporter->validateData();
    expect($personImporter->getProcessedRowsCount() === 1, 'real AbstractImporter CSV pipeline parses and validates one person row');
    expect(count($personBatchRepo->batches) === 1 && count($personBatchRepo->batches[0]->data) === 1, 'person CSV row is accepted into a production importer batch');
    expect(\Illuminate\Support\Facades\Validator::$lastRules['user_id'] === 'required|exists:users,id', 'person row validation checks that the submitted owner exists, not that it is within actor scope');
    $personBatch = new ImportBatchProbe($personBatchRepo->batches[0]->data, \Webkul\DataTransfer\Helpers\Import::ACTION_APPEND);
    $personImporter->importBatch($personBatch);
    expect((int) $personRepo->rows[202]['user_id'] === 11, 'CSV import reassigns victim-owned contact #202 from user 22 to actor user 11');
    expect($personRepo->rows[202]['name'] === 'Modified Contact' && $personRepo->rows[202]['job_title'] === 'Changed by CSV import', 'CSV import modifies victim-owned contact fields matched by email');
    expect($personImporter->getUpdatedItemsCount() === 1, 'person importer records one successful update');

    // ---------------- Person deletion through the real CSV validation + batch + importer flow ----------------
    $deleteEmail = 'delete.victim@example.test';
    $deletePersonRepo = new \Webkul\Contact\Repositories\PersonRepository([
        ['id'=>303,'name'=>'Delete Victim','emails'=>[['label'=>'work','value'=>$deleteEmail]],
         'contact_numbers'=>[['label'=>'work','value'=>'5550000333']], 'job_title'=>'Victim title',
         'organization_id'=>9,'user_id'=>22,'unique_id'=>'22|9|'.$deleteEmail.'|5550000333'],
    ]);
    expect(!in_array((int) $deletePersonRepo->rows[303]['user_id'], $authorizedUserIds, true), 'contact selected for deletion is also outside the actor individual scope');
    $deleteBatchRepo = new \Webkul\DataTransfer\Repositories\ImportBatchRepository;
    $deleteStorage = new \Webkul\DataTransfer\Helpers\Importers\Persons\Storage($deletePersonRepo);
    $deleteImporter = new \Webkul\DataTransfer\Helpers\Importers\Persons\Importer(
        $deleteBatchRepo, $deletePersonRepo, $attrRepo, new \Webkul\Attribute\Repositories\AttributeValueRepository, $deleteStorage
    );
    $deleteImporter->setImport(new ImportProbe(\Webkul\DataTransfer\Helpers\Import::ACTION_DELETE));
    $deleteImporter->setErrorHelper(new ErrorProbe);
    $writeCsv('imports/foreign-person-delete.csv', ['emails','contact_numbers'], [[
        json_encode([['label'=>'work','value'=>$deleteEmail]]), json_encode([['label'=>'work','value'=>'5550000333']])
    ]]);
    $deleteImporter->setSource(new \Webkul\DataTransfer\Helpers\Sources\CSV('imports/foreign-person-delete.csv', ','));
    $deleteImporter->validateData();
    expect($deleteImporter->getProcessedRowsCount() === 1, 'real CSV validation accepts deletion row by matching the foreign contact email');
    expect(count($deleteBatchRepo->batches) === 1 && count($deleteBatchRepo->batches[0]->data) === 1, 'foreign contact delete row is accepted into a production importer batch');
    $deleteBatch = new ImportBatchProbe($deleteBatchRepo->batches[0]->data, \Webkul\DataTransfer\Helpers\Import::ACTION_DELETE);
    $deleteImporter->importBatch($deleteBatch);
    expect(!isset($deletePersonRepo->rows[303]), 'CSV delete removes victim-owned contact #303 by email');
    expect($deleteImporter->getDeletedItemsCount() === 1, 'person importer records one successful deletion');

    echo "RESULT: 30 assertions passed; production Krayin CSV parser, AbstractImporter validation/batching, concrete importer/storage methods loaded. Framework, repositories and database are still test doubles.\n";

}
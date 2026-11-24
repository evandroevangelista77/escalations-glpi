<?php

/**
 * Runs with the GLPI core bootstrapped against a disposable, empty esc_race_* database.
 * Use an isolated network with no external egress. The Escalations plugin stays uninstalled.
 * The disposable MySQL instance must allow test triggers (log_bin_trust_function_creators=1
 * when binary logging is enabled).
 * GLPI_ROOT=/var/www/glpi ESCALATIONS_TEST_DB=esc_race_lab php tests/concurrency.php
 */

use Glpi\Kernel\Kernel;
use GlpiPlugin\Escalations\Api;
use GlpiPlugin\Escalations\Channels;
use GlpiPlugin\Escalations\Cron;
use GlpiPlugin\Escalations\Dispatcher;
use GlpiPlugin\Escalations\Engine;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\EventIntake;
use GlpiPlugin\Escalations\ItemWatcher;
use GlpiPlugin\Escalations\Lock;
use GlpiPlugin\Escalations\Log;
use GlpiPlugin\Escalations\Message;
use GlpiPlugin\Escalations\Outbox;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Settings;
use GlpiPlugin\Escalations\Slo;
use GlpiPlugin\Escalations\TicketWatcher;
use GlpiPlugin\Escalations\Transaction;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$database = getenv('ESCALATIONS_TEST_DB');
if (!$database || !preg_match('/^esc_race_[a-z0-9_]+$/D', $database)) {
    throw new RuntimeException('Use a disposable database named esc_race_*.');
}

$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Escalations\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php';
    }
});
$cli = dirname(__DIR__) . '/tools/tick.php';
if ($_SERVER['SCRIPT_FILENAME'] === $cli) {
    return; // auto_prepend_file: only provide the uninstalled plugin's autoloader.
}
putenv('GLPI_ROOT=' . $root);
chdir($root);
require $root . '/vendor/autoload.php';
(new Kernel('production'))->boot();
if ($DB->dbdefault !== $database || Plugin::isPluginActive('escalations')) {
    throw new RuntimeException('The active GLPI database must be the disposable database with Escalations uninstalled.');
}

function openInput(int $policy, string $key): array
{
    return ['policies_id' => $policy, 'source' => 'manual', 'dedup_key' => $key, 'title' => 'Concurrency'];
}

if (($argv[1] ?? '') === 'worker') {
    [$action, $id, $argument] = [$argv[2], (int) $argv[3], $argv[4]];
    echo "READY\n";
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(1);
    }
    try {
        $result = match ($action) {
            'open' => Escalation::open(openInput($id, $argument)),
            'ack' => Escalation::ack($id, (int) $argument, 'popup'),
            'resolve' => Escalation::resolve($id, 'manual', (int) $argument),
            'cancel' => Escalation::cancel($id, 'test', (int) $argument),
            'recover' => EventIntake::handle(['title' => 'Concurrency', 'dedup_key' => $argument, 'status' => 'resolved']),
            'event' => EventIntake::handle(['title' => 'Concurrency', 'dedup_key' => $argument, 'policy_id' => $id]),
            'tick' => Engine::tick(),
            'dispatch' => Dispatcher::retries($id ?: null),
            'cron' => Cron::cronEscalationsrun(new CronTask()),
            'cli' => cliCycle($cli),
        };
        echo json_encode(['code' => 200, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $e) {
        echo json_encode(['code' => $e->getCode(), 'error' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    }
    exit;
}

function cliCycle(string $script): int
{
    $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __FILE__, $script, '-v'], [
        0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR,
    ], $pipes);
    check(is_resource($process), 'CLI launched');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    check(proc_close($process) === 0 && preg_match('/ciclo concluído: (\d+) ação/', $output, $matches) === 1, 'CLI returned the action count');
    return (int) $matches[1];
}

function startWorker(string $action, int $id = 0, string $argument = ''): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $action, (string) $id, $argument], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR,
    ], $pipes);
    check(is_resource($process), 'worker launched');
    stream_set_timeout($pipes[1], 45);
    if (fgets($pipes[1]) !== "READY\n") {
        proc_terminate($process);
        throw new RuntimeException("Worker did not start: $action");
    }
    return [$process, $pipes];
}

function releaseWorker(array $worker): void
{
    fwrite($worker[1][0], "GO\n");
    fclose($worker[1][0]);
}

function finishWorker(array $worker): array
{
    [$process, $pipes] = $worker;
    $json = fgets($pipes[1]);
    if ($json === false) {
        proc_terminate($process);
        throw new RuntimeException('Worker exited without a result or exceeded the timeout.');
    }
    fclose($pipes[1]);
    check(proc_close($process) === 0, 'worker completed');
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function parallel(array ...$operations): array
{
    $workers = array_map(static fn (array $operation): array => startWorker(...$operation), $operations);
    foreach ($workers as $worker) {
        releaseWorker($worker);
    }
    return array_map(finishWorker(...), $workers);
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException("Failed: $label");
    }
}

function countEvents(int $id, array|string $events): int
{
    return (int) countElementsInTable(Log::TABLE, ['escalations_id' => $id, 'event' => $events]);
}

function forceDue(int $id): void
{
    global $DB;
    $DB->update(Escalation::TABLE, ['next_action_at' => date('Y-m-d H:i:s', time() - 30)], ['id' => $id]);
}

require dirname(__DIR__) . '/hook.php';
$tables = plugin_escalations_table_definitions(DBConnection::getDefaultPrimaryKeySignOption());
foreach (array_keys($tables) as $table) {
    check(!$DB->tableExists($table), 'Escalations tables must not exist before the suite');
}
$charset = DBConnection::getDefaultCharset();
$collation = DBConnection::getDefaultCollation();
foreach ($tables as $table => $definition) {
    $DB->doQuery("CREATE TABLE `$table` ($definition) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
}

$settings = ['sources' => 'manual', 'mail_enabled' => '0', 'teams_enabled' => '0', 'wa_provider' => 'off',
    'avisos_bridge' => '0', 'sink' => 'teams', 'outbox' => '1', 'owner_channels' => 'popup', 'fallback_channel' => 'none'];
Settings::set($settings);
[$user, $other] = array_map(
    static fn (array $row): int => (int) $row['id'],
    iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => 'id', 'LIMIT' => 2]), false)
);

$policy = Policy::save(['name' => 'Concurrency', 'users_id_owner' => $user, 'is_active' => 1, 'max_repeats' => 1]);
foreach ([1, 2] as $position) {
    Policy::saveLevel($policy, ['name' => "N$position", 'channels' => ['popup'], 'users' => [$user]]);
}

$opened = parallel(
    ['open', $policy, 'Ráce-Key'],
    ['open', $policy, 'race-key'],
    ['open', $policy, 'RACE-KEY'],
    ['open', $policy, 'race-key ']
);
check(array_column($opened, 'code') === [200, 200, 200, 200], 'all openings succeeded');
$id = $opened[0]['result'][0];
check(count(array_filter($opened, static fn (array $r): bool => $r['result'][1])) === 1, 'only one opening won');
check(array_unique(array_map(static fn (array $r): int => $r['result'][0], $opened)) === [$id], 'all callers received the same id');
check((int) Escalation::get($id)['occurrences'] === 4, 'no lost occurrence increments');
check(countEvents($id, 'opened') === 1 && countEvents($id, 'dedup') === 3 && countEvents($id, 'level_start') === 1, 'one opening and one N1 with three dedup logs');
check(countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => $id, 'channel' => 'popup']) === 1, 'one initial delivery');
echo "OK concurrent dedup, including collation-equivalent keys\n";

forceDue($id);
Lock::run('tick', static function () use ($id): void {
    $cycles = parallel(['tick'], ['cron'], ['cli']);
    check($cycles === array_fill(0, 3, ['code' => 200, 'result' => 0]), 'all entry points lost the same lock');
    check(Settings::get('last_tick') === '' && (int) Escalation::get($id)['level'] === 1 && countEvents($id, 'escalate') === 0, 'losing cycles made no changes');
});
$cycles = parallel(['tick'], ['cron'], ['cli']);
check(array_column($cycles, 'code') === [200, 200, 200] && array_sum(array_column($cycles, 'result')) === 1
    && (int) Escalation::get($id)['level'] === 2, 'native cron, engine and CLI advanced once');
check(countEvents($id, 'escalate') === 1 && countEvents($id, 'level_start') === 2, 'only one advance had effects');
echo "OK common cycle lock and single N1 to N2 transition\n";

forceDue($id);
Escalation::withLock($id, static function () use ($id, $user): void {
    $result = parallel(['tick']);
    check($result === [['code' => 200, 'result' => 0]], 'tick did not advance a locked escalation');
    Escalation::ack($id, $user, 'glpi');
});
Dispatcher::retries($id);
check(Engine::tick() === 0 && Escalation::get($id)['next_action_at'] === null && countEvents($id, 'repeat') === 0, 'ACK stopped a due repeat');
$DB->update(Escalation::TABLE, ['resolve_due_at' => date('Y-m-d H:i:s', time() - 30)], ['id' => $id]);
Engine::tick();
Engine::tick();
check((int) Escalation::get($id)['resolve_breached'] === 1 && countEvents($id, 'slo_breach') === 1, 'resolution SLO continued after ACK, with one breach');
check(countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => $id, 'kind' => 'slo_breach:resolve']) === 1, 'one owner warning');
echo "OK ACK stops advancement and resolution SLO continues\n";

$closed = parallel(['resolve', $id, (string) $user], ['cancel', $id, (string) $other]);
$codes = array_column($closed, 'code');
sort($codes);
check($codes === [200, 409], 'resolution and cancellation had exactly one winner');
check(countEvents($id, ['resolved', 'cancelled']) === 1, 'one terminal audit event');
$state = Escalation::get($id)['state'];
Dispatcher::retries($id);
check($state === ($closed[0]['code'] === 200 ? 'resolved' : 'cancelled'), 'terminal state matches the winning action');
check(countElementsInTable(Outbox::TABLE, ['escalations_id' => $id, 'status' => 'sim']) === ($state === 'resolved' ? 2 : 1), 'only a winning resolution announces');
[$next, $created] = Escalation::open(openInput($policy, 'race-key'));
check($created && $next !== $id, 'a new occurrence can open after a terminal state');
echo "OK resolution versus cancellation, with legitimate reopening\n";

$acks = parallel(['ack', $next, (string) $user], ['ack', $next, (string) $other]);
$codes = array_column($acks, 'code');
sort($codes);
check($codes === [200, 409], 'simultaneous ACK returns one conflict');
check((int) Escalation::get($next)['users_id_ack'] === ($acks[0]['code'] === 200 ? $user : $other), 'ACK author matches the winner');
check(countEvents($next, 'ack') === 1, 'one ACK audit event');
Dispatcher::retries($next);
check(countElementsInTable(Outbox::TABLE, ['escalations_id' => $next, 'status' => 'sim']) === 1, 'one ACK announcement');
check(countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => $next, 'channel' => 'popup', 'status' => 'acked']) === 1, 'one pending popup cleared');
check(Log::verify(Escalation::get($id)['trace_id'])[0] && Log::verify(Escalation::get($next)['trace_id'])[0], 'both audit chains verified');
echo "OK simultaneous ACK, single set of effects and intact trace hashes\n";

[$last] = Escalation::open(openInput($policy, 'last-level'));
foreach (['escalate', 'repeat', 'exhausted'] as $event) {
    forceDue($last);
    $cycles = parallel(['cron'], ['tick']);
    check(array_column($cycles, 'code') === [200, 200] && array_sum(array_column($cycles, 'result')) === 1, "one winning cycle for $event");
    check(countEvents($last, $event) === 1, "one audit entry for $event");
}
check(Escalation::get($last)['state'] === 'open' && Escalation::get($last)['next_action_at'] === null, 'exhaustion leaves the escalation open without advancing');
check(countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => $last, 'kind' => 'exhausted']) === 1, 'one exhaustion notice to the owner');
echo "OK one repeat and one exhaustion notice\n";

[$previous] = Escalation::open(openInput($policy, 'closing-key'));
$race = parallel(['cancel', $previous, (string) $user], ['open', $policy, 'closing-key']);
check(array_column($race, 'code') === [200, 200], 'opening and cancellation both completed');
[$openedId, $wasCreated] = $race[1]['result'];
check(Escalation::get($previous)['state'] === 'cancelled' && countEvents($previous, 'cancelled') === 1, 'the original occurrence closed once');
if ($wasCreated) {
    check($openedId !== $previous && Escalation::get($openedId)['state'] === 'open', 'opening after cancellation created a new occurrence');
} else {
    check($openedId === $previous && (int) Escalation::get($previous)['occurrences'] === 2, 'opening before cancellation grouped into the previous occurrence');
}
check(countElementsInTable(Escalation::TABLE, ['dedup_key' => 'closing-key', 'state' => ['open', 'acked']]) === (int) $wasCreated, 'at most one occurrence stayed active');
$manual = parallel(['open', $policy, ''], ['open', $policy, '']);
check(array_column($manual, 'code') === [200, 200] && $manual[0]['result'][1] && $manual[1]['result'][1]
    && $manual[0]['result'][0] !== $manual[1]['result'][0], 'manual openings without a deduplication key remain independent');
echo "OK cancellation racing with a new occurrence and independent unkeyed openings\n";

Settings::set(['sources' => 'event']);
[$code, $event] = EventIntake::handle(['title' => 'Concurrency', 'dedup_key' => 'recover-key', 'policy_id' => $policy]);
check($code === 201, 'webhook handler opened the event');
$recoveries = parallel(['recover', 0, 'recover-key'], ['recover', 0, 'recover-key']);
check(array_column($recoveries, 'code') === [200, 200], 'both recovery workers completed');
$actions = [];
foreach ($recoveries as $response) {
    [$status, $body] = $response['result'];
    check($status === 200, 'the duplicate recovery is accepted');
    $actions[] = $body['action'];
}
sort($actions);
check($actions === ['ignored', 'resolved'] && countEvents($event['id'], 'resolved') === 1
    && countEvents($event['id'], 'webhook') === 2, 'only the winning recovery has success effects on the trace');
echo "OK concurrent recovery events are idempotent\n";

function deliveryRows(int $id, array $filters = []): array
{
    global $DB;
    return iterator_to_array($DB->request([
        'FROM' => Escalation::DELIVERIES, 'WHERE' => ['escalations_id' => $id] + $filters, 'ORDER' => 'id',
    ]), false);
}

function dueDeliveries(int $id): void
{
    global $DB;
    $DB->update(Escalation::DELIVERIES, ['next_try' => date('Y-m-d H:i:s', time() - 10)], [
        'escalations_id' => $id, 'status' => 'failed', ['NOT' => ['next_try' => null]],
    ]);
}

function interruptWorker(string $event, string $condition, array $operation, ?Closure $beforeKill = null): void
{
    global $DB;
    $tag = md5($DB->dbdefault);
    $gate = $DB->quote("esc_test_gate_$tag");
    $reached = $DB->quote("esc_test_reached_$tag");
    $table = Escalation::DELIVERIES;
    $DB->doQuery("CREATE TRIGGER esc_test_interrupt BEFORE $event ON `$table` FOR EACH ROW
        BEGIN IF $condition THEN
            SET @esc_test_reached = GET_LOCK($reached, 0);
            SET @esc_test_gate = GET_LOCK($gate, 15);
        END IF; END");
    try {
        [$locked] = $DB->fetchRow($DB->doQuery("SELECT GET_LOCK($gate, 0)"));
        check((int) $locked === 1, 'test holds the interruption gate');
        try {
            $worker = startWorker(...$operation);
            releaseWorker($worker);
            $deadline = microtime(true) + 10;
            do {
                [$connection] = $DB->fetchRow($DB->doQuery("SELECT IS_USED_LOCK($reached)"));
                if (!$connection) {
                    usleep(20000);
                }
            } while (!$connection && microtime(true) < $deadline);
            check((bool) $connection, 'worker reached the selected crash point');
            if ($beforeKill) {
                $beforeKill();
            }
        } finally {
            try {
                if (isset($worker)) {
                    proc_terminate($worker[0], 9);
                    // Cancela a instrução no servidor antes de abrir o gate do trigger.
                    if (!empty($connection)) {
                        try {
                            $DB->doQuery('KILL CONNECTION ' . (int) $connection);
                        } catch (mysqli_sql_exception $e) {
                            if ($e->getCode() !== 1094) { // A sessão pode já ter sido encerrada pelo servidor.
                                throw $e;
                            }
                        }
                    }
                }
            } finally {
                $DB->doQuery("SELECT RELEASE_LOCK($gate)");
                if (isset($worker)) {
                    fclose($worker[1][1]);
                    proc_close($worker[0]);
                }
            }
        }
    } finally {
        $DB->doQuery('DROP TRIGGER esc_test_interrupt');
    }
}

function withLocalReceiver(Closure $test): void
{
    $dir = sys_get_temp_dir() . '/esc-race-' . bin2hex(random_bytes(8));
    check(mkdir($dir, 0700), 'temporary receiver directory');
    $receiver = $dir . '/receiver.php';
    $requests = $dir . '/requests';
    try {
        file_put_contents($receiver, <<<'ROUTER'
<?php
file_put_contents(__DIR__ . '/requests', file_get_contents('php://input') . "\n", FILE_APPEND | LOCK_EX);
http_response_code($_SERVER['REQUEST_URI'] === '/reject' ? 503 : 202);
ROUTER);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        check(is_resource($listener), 'reserve a local TCP port');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $process = proc_open([PHP_BINARY, '-S', $address, $receiver], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
        ], $pipes);
        check(is_resource($process), 'test receiver launched');
        $deadline = microtime(true) + 5;
        do {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.2);
            if (!$probe) {
                usleep(20000);
            }
        } while (!$probe && microtime(true) < $deadline);
        check(is_resource($probe), 'test receiver is listening on loopback');
        fclose($probe);
        $test('http://' . $address, static fn (): array => is_file($requests) ? file($requests, FILE_IGNORE_NEW_LINES) : []);
    } finally {
        if (isset($process) && is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        if (is_file($requests)) {
            unlink($requests);
        }
        unlink($receiver);
        rmdir($dir);
    }
}

Dispatcher::retries();
Settings::set(['sources' => 'manual']);
$deliveryPolicy = Policy::save(['name' => 'Delivery recovery', 'users_id_owner' => $user, 'is_active' => 1]);
Policy::saveLevel($deliveryPolicy, ['name' => 'N1', 'channels' => ['teams'], 'users' => [$user]]);
Policy::saveLevel($deliveryPolicy, ['name' => 'N2', 'channels' => ['teams'], 'users' => [$user]]);

interruptWorker('INSERT', "NEW.channel = 'teams' AND NEW.level = 1", ['open', $deliveryPolicy, 'interrupted-n1'], static function (): void {
    check(countElementsInTable(Escalation::TABLE, ['dedup_key' => 'interrupted-n1']) === 0, 'partially opened escalation is invisible');
});
check(countElementsInTable(Escalation::TABLE, ['dedup_key' => 'interrupted-n1']) === 0, 'interrupted N1 fully rolled back');
$createdByWorker = parallel(['open', $deliveryPolicy, 'interrupted-n1'])[0];
check($createdByWorker['code'] === 200 && $createdByWorker['result'][1], 'source can retry opening after interruption');
$deliveryEsc = $createdByWorker['result'][0];
$queued = deliveryRows($deliveryEsc, ['level' => 1]);
check(count($queued) === 1 && $queued[0]['status'] === 'queued' && $queued[0]['next_try'] !== null, 'N1 intention remains queued after opener exits');
check(countElementsInTable(Outbox::TABLE, ['escalations_id' => $deliveryEsc]) === 0, 'no send before the worker');
check(Dispatcher::retries($deliveryEsc) === 1 && Dispatcher::retries($deliveryEsc) === 0, 'queued N1 is sent once');
check(deliveryRows($deliveryEsc, ['level' => 1])[0]['status'] === 'sent', 'queued N1 confirmed by the simulator');
echo "OK interruption before staging and recovery after the staging process exits\n";

forceDue($deliveryEsc);
interruptWorker('INSERT', "NEW.escalations_id = $deliveryEsc AND NEW.level = 2", ['tick'], static function () use ($deliveryEsc): void {
    check((int) Escalation::get($deliveryEsc)['level'] === 1, 'a level is not published before its deliveries');
});
check((int) Escalation::get($deliveryEsc)['level'] === 1 && deliveryRows($deliveryEsc, ['level' => 2]) === [], 'interrupted advancement fully rolled back');
Engine::tick();
check((int) Escalation::get($deliveryEsc)['level'] === 2 && countEvents($deliveryEsc, 'escalate') === 1
    && count(deliveryRows($deliveryEsc, ['level' => 2, 'status' => 'sent'])) === 1, 'next cycle recovers the advancement once');
echo "OK automatic recovery of an interrupted level transition\n";

[$legacy] = Escalation::open(openInput($deliveryPolicy, 'legacy-queue'));
$DB->update(Escalation::DELIVERIES, ['next_try' => null], ['escalations_id' => $legacy, 'status' => 'queued']);
Dispatcher::retries($legacy);
check(deliveryRows($legacy)[0]['status'] === 'uncertain' && Dispatcher::retries($legacy) === 0, 'legacy queued row is flagged without replay');
echo "OK explicit treatment of ambiguous legacy queue entries\n";

$DB->beginTransaction();
[$uncommitted] = Escalation::open(openInput($deliveryPolicy, 'outer-rollback'));
check(parallel(['dispatch', $uncommitted])[0] === ['code' => 200, 'result' => 0], 'worker cannot see uncommitted intents');
$DB->rollBack();
check(!Escalation::get($uncommitted) && deliveryRows($uncommitted) === [], 'caller rollback discards the escalation and its queue');
echo "OK worker dispatches only committed intents\n";

[$busy] = Escalation::open(openInput($deliveryPolicy, 'busy-queue'));
for ($i = 0; $i < 49; $i++) {
    Engine::resend($busy, $user);
}
[$ready] = Escalation::open(openInput($deliveryPolicy, 'ready-behind-busy'));
Escalation::withLock($busy, static function () use ($busy, $ready): void {
    check(count(deliveryRows($busy, ['status' => 'queued'])) === 50, 'the first dispatch page is occupied');
    check(parallel(['dispatch'])[0]['code'] === 200
        && count(deliveryRows($ready, ['status' => 'sent'])) === 1, 'a locked first page does not starve other occurrences');
});
check(Dispatcher::retries($busy) === 50 && Dispatcher::retries($busy) === 0, 'the occupied batch recovers when its lock is released');
echo "OK a contended queue page does not starve later deliveries\n";

withLocalReceiver(static function (string $url, Closure $received) use ($DB, $user, $other): void {
    $users = iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users',
        'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => 'id', 'LIMIT' => 3]), false);
    $ids = array_map(static fn (array $u): int => (int) $u['id'], $users);
    check(count($ids) === 3, 'three GLPI users for the delivery test');
    foreach ($ids as $index => $uid) {
        $DB->update('glpi_users', ['mobile' => '1190000000' . $index], ['id' => $uid]);
    }
    Settings::set(['wa_provider' => 'webhook', 'wa_webhook_url' => $url, 'max_attempts' => '2']);
    $policy = Policy::save(['name' => 'Multiple recipients', 'users_id_owner' => $user, 'is_active' => 1]);
    Policy::saveLevel($policy, ['name' => 'N1', 'channels' => ['whatsapp'], 'users' => $ids]);
    $DB->beginTransaction();
    try {
        [$uncommitted] = Escalation::open(openInput($policy, 'no-local-transaction-send'));
        check(count(deliveryRows($uncommitted, ['status' => 'queued'])) === 3, 'the caller sees its staged batch');
        check(Dispatcher::retries($uncommitted) === 0 && $received() === [], 'the same connection cannot send uncommitted intents');
    } finally {
        $DB->rollBack();
    }
    check(deliveryRows($uncommitted) === [], 'the rolled back batch leaves no delivery');

    [$multi] = Escalation::open(openInput($policy, 'interrupted-multi'));
    $batch = deliveryRows($multi);
    check(array_column($batch, 'status') === ['queued', 'queued', 'queued'], 'entire recipient batch committed before any send');

    $first = (int) $batch[0]['id'];
    interruptWorker('UPDATE', "NEW.id = $first AND NEW.status = 'sending'", ['dispatch', $multi]);
    check(array_column(deliveryRows($multi), 'status') === ['queued', 'queued', 'queued'] && $received() === [],
        'interruption before claiming the first attempt leaves the whole batch recoverable');
    echo "OK worker killed after staging but before transport\n";

    $second = (int) $batch[1]['id'];
    interruptWorker('UPDATE', "NEW.id = $second AND NEW.status = 'sent'", ['dispatch', $multi], static function () use ($multi, $received): void {
        check(count($received()) === 2, 'local provider received the first and second requests before the crash');
        check(array_column(deliveryRows($multi), 'status') === ['sent', 'sending', 'queued'], 'confirmation interrupted between recipients');
    });
    check(Dispatcher::retries($multi) === 2 && Dispatcher::retries($multi) === 0, 'recovery handles the abandoned and remaining recipients once');
    $batch = deliveryRows($multi);
    check(array_column($batch, 'status') === ['sent', 'uncertain', 'sent'], 'confirmed, ambiguous and unsent rows are handled separately');
    check((int) $batch[1]['attempts'] === 1 && !$batch[1]['sent_at'] && !$batch[1]['next_try'], 'uncertain outcome retains attempts without false sent status');
    check(count($received()) === 3 && count(array_unique($received())) === 3, 'provider received no duplicate request');
    check(countElementsInTable(Outbox::TABLE, ['deliveries_id' => $second]) === 0, 'no false confirmation in the outbox');
    echo "OK worker killed after local HTTP acceptance during a multi-recipient batch\n";

    Settings::set(['wa_webhook_url' => $url . '/reject', 'fallback_channel' => 'teams']);
    $policy = Policy::save(['name' => 'Retries and fallback', 'users_id_owner' => $user, 'is_active' => 1]);
    Policy::saveLevel($policy, ['name' => 'N1', 'channels' => ['whatsapp'], 'users' => [$user]]);
    [$retry] = Escalation::open(openInput($policy, 'known-failure'));
    Dispatcher::retries($retry);
    $failed = deliveryRows($retry)[0];
    check($failed['status'] === 'failed' && (int) $failed['attempts'] === 1
        && strtotime($failed['next_try']) - strtotime($failed['date_mod']) === 60, 'known rejection schedules first backoff');
    check(Dispatcher::retries($retry) === 0 && count($received()) === 4, 'retry waits for backoff');
    dueDeliveries($retry);
    $workers = parallel(['dispatch', $retry], ['dispatch', $retry]);
    check(array_column($workers, 'code') === [200, 200], 'competing retry workers completed');
    $rows = deliveryRows($retry);
    check(array_column($rows, 'status') === ['failed', 'sent'] && count($received()) === 5, 'one terminal retry and one simulated reserve');
    check($rows[1]['channel'] === 'teams' && countEvents($retry, 'fallback') === 1 && !$rows[0]['next_try'], 'failure and fallback were recorded once');
    echo "OK concurrent retry, original backoff and one fallback\n";

    Settings::set(['max_attempts' => '1']);
    [$reserve] = Escalation::open(openInput($policy, 'interrupted-fallback'));
    $requestsBefore = count($received());
    interruptWorker('UPDATE', "NEW.escalations_id = $reserve AND NEW.channel = 'teams' AND NEW.status = 'sent'", ['dispatch', $reserve]);
    check(array_column(deliveryRows($reserve), 'status') === ['failed', 'queued'], 'the reserve intention survives interruption independently of the primary');
    check(Dispatcher::retries($reserve) === 1 && Dispatcher::retries($reserve) === 0 && count($received()) === $requestsBefore + 1,
        'reserve recovers without replaying the primary send');
    echo "OK recovery after the failure and reserve committed together\n";

    Settings::set(['max_attempts' => '2', 'fallback_channel' => 'none', 'owner_channels' => 'popup,whatsapp']);
    $policy = Policy::save(['name' => 'Stale deliveries', 'users_id_owner' => $user, 'is_active' => 1]);
    Policy::saveLevel($policy, ['name' => 'N1', 'channels' => ['popup', 'whatsapp'], 'users' => [$user], 'users_notify' => [$other]]);
    [$ack] = Escalation::open(openInput($policy, 'retry-ack'));
    Dispatcher::retries($ack);
    dueDeliveries($ack);
    Escalation::withLock($ack, static function () use ($ack, $user): void {
        check(parallel(['dispatch', $ack])[0] === ['code' => 200, 'result' => 0], 'retry skipped the occurrence while ACK held the lock');
        Escalation::ack($ack, $user, 'popup');
    });
    Settings::set(['wa_webhook_url' => $url]);
    Dispatcher::retries($ack);
    $roles = array_column(deliveryRows($ack, ['kind' => 'level', 'channel' => 'whatsapp']), 'status', 'role');
    check(($roles['act'] ?? '') === 'obsolete' && ($roles['notify'] ?? '') === 'sent', 'ACK suppresses actionable retry and keeps an informational retry');
    check(count(deliveryRows($ack, ['kind' => 'level', 'channel' => 'popup', 'role' => 'notify', 'status' => 'queued'])) === 1, 'informational popup survives ACK');
    check(!str_contains((string) array_slice($received(), -1)[0], 'Responda *ACK'), 'informational retry has no ACK instruction');

    $DB->update(Escalation::TABLE, ['resolve_due_at' => date('Y-m-d H:i:s', time() - 30)], ['id' => $ack]);
    interruptWorker('INSERT', "NEW.escalations_id = $ack AND NEW.kind = 'slo_breach:resolve'", ['tick']);
    check((int) Escalation::get($ack)['resolve_breached'] === 0 && deliveryRows($ack, ['kind' => 'slo_breach:resolve']) === [], 'interrupted SLO claim rolled back');
    Engine::tick();
    $slo = deliveryRows($ack, ['kind' => 'slo_breach:resolve', 'channel' => 'whatsapp']);
    check(count($slo) === 1 && $slo[0]['status'] === 'sent' && countEvents($ack, 'slo_breach') === 1, 'resolution SLO survives and retries an interrupted staging after ACK');

    Settings::set(['wa_webhook_url' => $url . '/reject']);
    [$closed] = Escalation::open(openInput($policy, 'retry-resolve'));
    $DB->update(Escalation::TABLE, ['resolve_due_at' => date('Y-m-d H:i:s', time() - 30)], ['id' => $closed]);
    Engine::tick();
    dueDeliveries($closed);
    Escalation::withLock($closed, static function () use ($closed, $user): void {
        check(parallel(['dispatch', $closed])[0] === ['code' => 200, 'result' => 0], 'retry skipped while resolution held the lock');
        Escalation::resolve($closed, 'manual', $user);
    });
    Settings::set(['wa_webhook_url' => $url]);
    Dispatcher::retries($closed);
    check(count(deliveryRows($closed, ['kind' => 'level', 'channel' => 'whatsapp', 'status' => 'obsolete'])) === 2, 'terminal state discards both superseded level messages');
    check(count(deliveryRows($closed, ['kind' => 'slo_breach:resolve', 'channel' => 'whatsapp', 'status' => 'sent'])) === 1, 'recorded SLO breach remains valid after closure');
    check(count(deliveryRows($closed, ['channel' => 'popup', 'status' => ['queued', 'shown']])) === 0, 'closed occurrence has no pending popup');
    echo "OK retry races with ACK and resolution, preserving relevant SLO notices\n";

    [$cancelled] = Escalation::open(openInput($policy, 'delivery-cancel'));
    Escalation::cancel($cancelled, 'test', $user);
    $requestsBefore = count($received());
    Dispatcher::retries($cancelled);
    check(count($received()) === $requestsBefore
        && count(deliveryRows($cancelled, ['channel' => 'whatsapp', 'status' => 'obsolete'])) === 2, 'cancellation suppresses the unsent level notices');

    [$manualResend] = Escalation::open(openInput($policy, 'manual-resend'));
    Engine::resend($manualResend, $user);
    Engine::resend($manualResend, $user);
    check(array_map('intval', array_column(deliveryRows($manualResend, ['channel' => 'whatsapp', 'role' => 'act']), 'round')) === [0, 100, 101],
        'explicit manual resends stay independent');
    echo "OK cancellation suppresses pending operational messages and manual resends stay distinct\n";

    [$withoutCopy] = Escalation::open(openInput($policy, 'optional-outbox-failure'));
    $before = count($received());
    $outbox = Outbox::TABLE;
    $DB->doQuery("CREATE TRIGGER esc_test_outbox_failure BEFORE INSERT ON `$outbox` FOR EACH ROW
        BEGIN IF NEW.escalations_id = $withoutCopy THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test outbox failure';
        END IF; END");
    try {
        Dispatcher::retries($withoutCopy);
        check(count(deliveryRows($withoutCopy, ['channel' => 'whatsapp', 'status' => 'sent'])) === 2
            && count($received()) === $before + 2 && Dispatcher::retries($withoutCopy) === 0,
            'a failure writing the optional copy does not replay accepted deliveries');
    } finally {
        $DB->doQuery('DROP TRIGGER esc_test_outbox_failure');
    }
    echo "OK confirmed delivery survives failure of the optional outbox copy\n";

    // ---------------------------------------------------------------- modo simulação
    if (Channels::userEmail($user) === '') {
        $DB->insert('glpi_useremails', ['users_id' => $user, 'email' => 'sim-lab@example.test', 'is_default' => 1]);
    }
    $DB->delete('glpi_useremails', ['users_id' => $other]);
    check(Channels::userEmail($user) !== '' && Channels::userEmail($other) === '',
        'fixture: one recipient has an e-mail and the other does not');

    $outboxRows = static fn (int $id): array => iterator_to_array($DB->request([
        'FROM' => Outbox::TABLE, 'WHERE' => ['escalations_id' => $id], 'ORDER' => 'id',
    ]), false);

    Settings::set(['sources' => 'manual', 'sink' => 'email', 'mail_enabled' => '0', 'teams_enabled' => '1',
        'teams_url' => $url, 'wa_provider' => 'off', 'fallback_channel' => 'teams', 'max_attempts' => '3',
        'outbox' => '1', 'owner_channels' => 'popup']);
    $emailPolicy = Policy::save(['name' => 'Simulation e-mail', 'users_id_owner' => $user, 'is_active' => 1]);
    Policy::saveLevel($emailPolicy, ['name' => 'N1', 'channels' => ['email'], 'users' => [$user]]);
    $before = count($received());
    [$simMail] = Escalation::open(['title' => 'Simulated e-mail message'] + openInput($emailPolicy, 'sim-email-message'));
    check(Dispatcher::retries($simMail) === 1 && Dispatcher::retries($simMail) === 0, 'the simulated e-mail is processed once');
    $rows = deliveryRows($simMail);
    check(count($received()) === $before && count($rows) === 1 && $rows[0]['status'] === 'sent'
        && $rows[0]['provider_id'] === 'simulado' && $rows[0]['channel'] === 'email',
        'a simulated channel works even with mail disabled and never reaches a real fallback');
    $copies = $outboxRows($simMail);
    check(count($copies) === 1 && $copies[0]['status'] === 'sim' && $copies[0]['subject'] !== ''
        && str_contains((string) $copies[0]['body_html'], 'Simulated e-mail message'),
        'the outbox keeps the exact simulated e-mail content and result');

    $missingPolicy = Policy::save(['name' => 'Simulation without e-mail', 'users_id_owner' => $user, 'is_active' => 1]);
    Policy::saveLevel($missingPolicy, ['name' => 'N1', 'channels' => ['email'], 'users' => [$other]]);
    $before = count($received());
    [$simMissing] = Escalation::open(['title' => 'Simulated e-mail missing recipient'] + openInput($missingPolicy, 'sim-email-missing'));
    check(Dispatcher::retries($simMissing) === 1 && Dispatcher::retries($simMissing) === 0, 'the invalid simulated e-mail is terminal');
    $rows = deliveryRows($simMissing);
    check(count($received()) === $before && count($rows) === 1 && $rows[0]['status'] === 'failed'
        && $rows[0]['next_try'] === null && str_contains((string) $rows[0]['error'], 'sem e-mail')
        && count(deliveryRows($simMissing, ['channel' => 'teams'])) === 0,
        'a simulated failure does not retry or fall back to a real channel');
    $copies = $outboxRows($simMissing);
    check(count($copies) === 1 && $copies[0]['status'] === 'failed' && str_contains((string) $copies[0]['error'], 'sem e-mail')
        && str_contains((string) $copies[0]['body_html'], 'Simulated e-mail missing recipient'),
        'the outbox records the content and the failed simulated result');
    echo "OK simulated e-mail stays local and reports its result in the outbox\n";

    Settings::set(['sink' => 'whatsapp,teams', 'wa_provider' => 'webhook', 'wa_webhook_url' => $url,
        'teams_enabled' => '1', 'teams_url' => $url, 'fallback_channel' => 'none', 'owner_channels' => 'popup']);
    $waPolicy = Policy::save(['name' => 'Simulation WhatsApp', 'users_id_owner' => $user, 'is_active' => 1]);
    Policy::saveLevel($waPolicy, ['name' => 'N1', 'channels' => ['whatsapp'], 'users' => [$user]]);
    $before = count($received());
    [$simWa] = Escalation::open(['title' => 'Simulated WhatsApp message'] + openInput($waPolicy, 'sim-wa-message'));
    check(Dispatcher::retries($simWa) === 1 && Dispatcher::retries($simWa) === 0, 'the simulated WhatsApp is processed once');
    $rows = deliveryRows($simWa, ['channel' => 'whatsapp', 'kind' => 'level']);
    check(count($received()) === $before && count($rows) === 1 && $rows[0]['status'] === 'sent' && $rows[0]['provider_id'] === 'simulado',
        'simulated WhatsApp never reaches the gateway');
    $copies = $outboxRows($simWa);
    check(count($copies) === 1 && $copies[0]['status'] === 'sim'
        && str_contains((string) $copies[0]['body_text'], 'Simulated WhatsApp message')
        && str_contains((string) $copies[0]['body_text'], 'Responda *ACK'),
        'the outbox keeps the exact simulated WhatsApp content');

    Escalation::ack($simWa, $user, 'whatsapp');
    Dispatcher::retries($simWa);
    Escalation::resolve($simWa, 'manual', $user);
    Dispatcher::retries($simWa);
    check(count($received()) === $before, 'ACK and resolution announcements are suppressed during simulation');
    $copies = $outboxRows($simWa);
    $ackCopy = array_values(array_filter($copies, static fn (array $c): bool => str_contains((string) $c['body_text'], 'Reconhecido por')));
    $resCopy = array_values(array_filter($copies, static fn (array $c): bool => str_contains((string) $c['body_text'], 'Resolvido após')));
    check(count($ackCopy) === 1 && $ackCopy[0]['status'] === 'sim' && count($resCopy) === 1 && $resCopy[0]['status'] === 'sim'
        && count(deliveryRows($simWa, ['kind' => ['announce:ack', 'announce:resolve'], 'status' => 'sent'])) === 2,
        'the outbox records the simulated ACK and resolution announcements');
    echo "OK simulated WhatsApp, ACK and resolution stay local and keep their outbox copies\n";

    Settings::set(['sink' => 'teams']);
    $before = count($received());
    $realReply = Channels::whatsappReply('5511999999999', 'ACK real');
    check($realReply['ok'] && count($received()) === $before + 1,
        'a real WhatsApp reply still reaches the gateway when the channel is not simulated');
    Settings::set(['sink' => 'whatsapp,teams']);
    $before = count($received());
    $simReply = Channels::whatsappReply('5511999999999', 'ACK simulated');
    check($simReply['ok'] && !empty($simReply['detail']['simulado']) && count($received()) === $before,
        'a simulated WhatsApp reply never reaches the gateway');
    echo "OK ACK replies respect the WhatsApp simulation\n";

    Settings::set(['sink' => 'teams', 'teams_enabled' => '1', 'teams_url' => $url]);
    $before = count($received());
    $realTeams = Channels::teams(Message::context(Escalation::get($simWa), Policy::level($waPolicy, 1), 'level'));
    check($realTeams['ok'] && count($received()) === $before + 1,
        'an explicit channel test still sends to the provider while simulation is active');
    echo "OK explicit channel tests remain real and are documented separately\n";

    Settings::set(['sink' => 'teams', 'mail_enabled' => '0', 'teams_enabled' => '0', 'teams_url' => '',
        'wa_provider' => 'webhook', 'wa_webhook_url' => $url, 'fallback_channel' => 'none', 'max_attempts' => '2',
        'owner_channels' => 'popup,whatsapp', 'outbox' => '1', 'sources' => 'manual']);
});

function expectConflict(int $code, Closure $action, string $label): void
{
    try {
        $action();
    } catch (RuntimeException $e) {
        check($e->getCode() === $code, $label);
        return;
    }
    check(false, $label);
}

Config::deleteConfigurationValues(Settings::CONTEXT, ['sources', 'owner_channels']);
check(Settings::sourceOn('ticket') && Settings::get('owner_channels') === Settings::DEFAULTS['owner_channels'],
    'defaults apply when the configuration keys are absent');
Settings::set(['sources' => '', 'owner_channels' => '', 'wa_provider' => 'off', 'last_scan' => '']);
check(Settings::sourceOn('manual') && !array_filter(array_keys(Settings::SOURCE_LABELS), Settings::sourceOn(...)),
    'explicit empty source selection leaves only manual openings enabled');
check(Settings::channels(Settings::get('owner_channels')) === [], 'explicit empty owner channel selection survives persistence');
check(EventIntake::handle(['title' => 'Disabled webhook'])[0] === 403, 'explicit empty sources also disables webhook intake');
echo "OK explicit empty configurations stay empty, with defaults for missing keys\n";

$invalid = Policy::save(['name' => 'Invalid N1', 'is_active' => 1]);
$before = array_map(fn (string $table): int => countElementsInTable($table), [Escalation::TABLE, Log::TABLE, Escalation::DELIVERIES]);
expectConflict(422, static fn () => Escalation::open(openInput($invalid, 'missing-n1')), 'direct opening reports missing N1');
$second = Policy::saveLevel($invalid, ['name' => 'N2', 'channels' => ['popup'], 'users' => [$user]]);
$DB->update(Policy::LEVELS, ['position' => 2], ['id' => $second]);
Settings::set(['sources' => 'event,plugin']);
check(EventIntake::handle(['policy_id' => $invalid, 'title' => 'Only N2'])[0] === 422, 'webhook reports missing N1');
Policy::saveRule($invalid, ['name' => 'Invalid N1 API', 'source' => 'plugin', 'plugin_type' => 'esc-test|invalid', 'is_active' => 1]);
check((int) (Policy::match('plugin', 3, 0, 0, 'Only N2', 'esc-test', 'invalid')['policies_id'] ?? 0) === $invalid,
    'the plugin API fixture matches the hierarchy without N1');
check(Api::escalate(['plugin' => 'esc-test', 'type' => 'invalid', 'key' => 'missing-n1', 'title' => 'Only N2']) === null,
    'plugin API reports missing N1 as failure');
$first = Policy::saveLevel($invalid, ['position' => 1, 'name' => 'N1', 'channels' => ['popup'], 'users' => [$user]]);
$DB->update(Policy::LEVELS, ['channels' => 'unknown'], ['id' => $first]);
expectConflict(422, static fn () => Escalation::open(openInput($invalid, 'unknown-n1-channel')), 'N1 requires a known channel');
$DB->update(Policy::LEVELS, ['channels' => 'popup'], ['id' => $first]);
$DB->delete(Policy::TARGETS, ['levels_id' => $first]);
expectConflict(422, static fn () => Escalation::open(openInput($invalid, 'no-n1-target')), 'N1 requires a configured target');
check(array_map(fn (string $table): int => countElementsInTable($table), [Escalation::TABLE, Log::TABLE, Escalation::DELIVERIES]) === $before,
    'invalid N1 leaves no occurrence, audit entry or delivery');
[$legacyNoLevel] = Escalation::open(openInput($invalid, 'legacy-no-n1') + ['no_start' => true]);
expectConflict(409, static fn () => Escalation::open(openInput($invalid, 'legacy-no-n1')), 'legacy level-zero row cannot be reported as a successful deduplication');
check((int) Escalation::get($legacyNoLevel)['occurrences'] === 1, 'rejected legacy deduplication does not increment occurrences');
$DB->insert('glpi_groups', ['name' => 'Empty N1 group']);
Policy::saveLevel($invalid, ['name' => 'N1', 'channels' => ['popup'], 'groups' => [(int) $DB->insertId()]], $first);
[$emptyGroup] = Escalation::open(openInput($invalid, 'empty-n1-group'));
check(countEvents($emptyGroup, 'no_recipient') === 1, 'a configured but empty group preserves skip behavior');
Engine::tick();
check((int) Escalation::get($emptyGroup)['level'] === 2, 'a configured but empty N1 advances to N2');
echo "OK invalid N1 fails atomically on all entry points and an empty group still advances\n";

function nativeRow(string $table, array $fields = []): int
{
    global $DB;
    $DB->insert($table, $fields + ['name' => 'Native Escalations regression', 'entities_id' => 0]);
    return (int) $DB->insertId();
}

function openNativeFixture(int $policy, string $source, int $item): int
{
    return Escalation::open(['source' => $source, 'items_id' => $item] + openInput($policy, "$source:$item"))[0];
}

$nativePolicy = Policy::save(['name' => 'Native regression', 'users_id_owner' => $user, 'is_active' => 1]);
Policy::saveLevel($nativePolicy, ['name' => 'N1', 'channels' => ['popup', 'teams', 'email'], 'users' => [$user]]);
Policy::saveLevel($nativePolicy, ['name' => 'N2', 'channels' => ['popup', 'teams'], 'users' => [$user]]);
[$noOwnerChannels] = Escalation::open(openInput($nativePolicy, 'empty-owner-channels'));
$DB->update(Escalation::TABLE, ['ack_due_at' => date('Y-m-d H:i:s', time() - 1)], ['id' => $noOwnerChannels]);
Engine::tick();
check(countEvents($noOwnerChannels, 'slo_breach') === 1 && deliveryRows($noOwnerChannels, ['kind' => 'slo_breach:ack']) === [],
    'empty owner channels keeps the SLO audit without restoring any channel');
$DB->insert('glpi_projectstates', ['name' => 'Finished regression', 'is_finished' => 1]);
$finishedState = (int) $DB->insertId();
$nativeCases = [
    ['ticket', 'glpi_tickets', ['status' => Ticket::SOLVED], 'resolved'],
    ['ticket', 'glpi_tickets', ['status' => Ticket::APPROVAL], 'open'],
    ['ticket', 'glpi_tickets', ['is_deleted' => 1], 'cancelled'],
    ['problem', 'glpi_problems', ['status' => Problem::CLOSED], 'resolved'],
    ['problem', 'glpi_problems', ['status' => Problem::OBSERVED], 'open'],
    ['change', 'glpi_changes', ['status' => Change::SOLVED], 'resolved'],
    ['change', 'glpi_changes', ['status' => Change::CANCELED], 'cancelled'],
    ['change', 'glpi_changes', ['status' => Change::REFUSED], 'cancelled'],
    ['change', 'glpi_changes', ['status' => Change::OBSERVED], 'open'],
    ['project', 'glpi_projects', ['percent_done' => 100], 'resolved'],
    ['projecttask', 'glpi_projecttasks', ['projectstates_id' => $finishedState], 'resolved'],
];
$cases = [];
foreach ($nativeCases as [$source, $table, $terminal, $expected]) {
    $fields = in_array($source, ['project', 'projecttask'], true)
        ? ['plan_start_date' => date('Y-m-d H:i:s', time() - 3600), 'plan_end_date' => date('Y-m-d H:i:s', time() - 600)]
        : ['status' => CommonITILObject::INCOMING, 'date' => date('Y-m-d H:i:s', time() - 3600), 'time_to_resolve' => date('Y-m-d H:i:s', time() - 600)];
    $item = nativeRow($table, $fields);
    $esc = openNativeFixture($nativePolicy, $source, $item);
    $DB->update($table, $terminal, ['id' => $item]);
    $cases[] = [$esc, $expected];
    if ($expected !== 'open') {
        expectConflict(409, static fn () => openNativeFixture($nativePolicy, $source, $item), 'finished origin rejects another opening');
    }
}
Settings::set(['sources' => '', 'last_scan' => '']);
TicketWatcher::scan();
foreach ($cases as [$esc, $expected]) {
    check(Escalation::get($esc)['state'] === $expected, "watcher recognizes native state for occurrence $esc");
}
echo "OK native closure respects cancelled, refused, completed and ambiguous states\n";

foreach (['change', 'projecttask'] as $source) {
    Policy::saveRule($nativePolicy, ['name' => "SLA $source", 'source' => $source, 'sla_pct' => 50, 'is_active' => 1]);
}
$closedChange = nativeRow('glpi_changes', ['status' => Change::REFUSED, 'date' => date('Y-m-d H:i:s', time() - 3600),
    'time_to_resolve' => date('Y-m-d H:i:s', time() - 600)]);
$closedTask = nativeRow('glpi_projecttasks', ['projectstates_id' => $finishedState, 'plan_start_date' => date('Y-m-d H:i:s', time() - 3600),
    'plan_end_date' => date('Y-m-d H:i:s', time() - 600)]);
Settings::set(['sources' => 'change,projecttask', 'last_scan' => '']);
TicketWatcher::scan();
check(countElementsInTable(Escalation::TABLE, ['dedup_key' => ["change:$closedChange", "projecttask:$closedTask"]]) === 0,
    'native scan cannot open overdue terminal items');

$item = nativeRow('glpi_changes', ['status' => Change::INCOMING]);
$esc = openNativeFixture($nativePolicy, 'change', $item);
$nativeEsc = Escalation::get($esc);
Escalation::withLock($esc, static fn () => Transaction::run(
    static fn () => Dispatcher::enqueue($nativeEsc, 0, 'teams', 0, 0, 'slo_breach:ack')
));
forceDue($esc);
$DB->update(Escalation::DELIVERIES, [
    'status' => 'failed', 'attempts' => 1, 'next_try' => date('Y-m-d H:i:s', time() - 1),
], ['escalations_id' => $esc, 'kind' => 'level', 'channel' => 'email']);
$DB->update(Escalation::TABLE, ['resolve_due_at' => date('Y-m-d H:i:s', time() - 30)], ['id' => $esc]);
$DB->update('glpi_changes', ['status' => Change::CANCELED], ['id' => $item]);
Settings::set(['last_scan' => date('Y-m-d H:i:s')]);
expectConflict(409, static fn () => Engine::resend($esc, $user), 'manual resend rejects a closed origin before the scan');
check(!array_filter(Dispatcher::pending($user, 40), static fn (array $p): bool => $p['esc_id'] === $esc),
    'popup suppresses the actionable delivery before the scan');
check(count(deliveryRows($esc, ['kind' => 'level', 'channel' => 'popup', 'status' => 'acked'])) === 1,
    'the pending popup was discarded while the occurrence was still open');
Engine::tick();
check(Escalation::get($esc)['state'] === 'cancelled' && countEvents($esc, ['escalate', 'repeat', 'slo_breach']) === 0,
    'a throttled scan cannot stage newly obsolete escalations or SLOs');
check(count(deliveryRows($esc, ['kind' => 'level', 'channel' => 'teams', 'status' => 'obsolete'])) === 1,
    'dispatcher discards already queued actionable delivery before transport');
$retry = deliveryRows($esc, ['kind' => 'level', 'channel' => 'email', 'status' => 'obsolete']);
check(count($retry) === 1 && (int) $retry[0]['attempts'] === 1 && $retry[0]['next_try'] === null,
    'dispatcher discards the due retry without scheduling another attempt');
check(count(deliveryRows($esc, ['kind' => 'slo_breach:ack', 'status' => 'sent'])) === 1, 'historical SLO breach remains eligible');
$copies = iterator_to_array($DB->request(['FROM' => Outbox::TABLE, 'WHERE' => ['escalations_id' => $esc]]), false);
check(count($copies) === 1 && !str_contains($copies[0]['body_text'], 'Assumir:'), 'historical SLO copy has no ACK action');
echo "OK closed native origin blocks popup, resend, queued delivery and new SLOs while preserving a historical breach\n";

Settings::set(['sources' => 'plugin,event']);
$routeA = Policy::save(['name' => 'Route A test', 'is_active' => 1]);
$routeB = Policy::save(['name' => 'Route B test', 'is_active' => 1]);
foreach ([$routeA => 'Route Alpha', $routeB => 'Route Beta'] as $pid => $text) {
    Policy::saveLevel($pid, ['name' => 'N1', 'channels' => ['popup'], 'users' => [$other]]);
    Policy::saveRule($pid, ['name' => $text, 'source' => 'plugin', 'plugin_type' => 'route-test|tracked',
        'match_text' => $text, 'sla_pct' => 50, 'is_active' => 1]);
}
$aged = ['start_at' => time() - 300, 'due_at' => time() - 30];
$base = ['plugin' => 'route-test', 'type' => 'tracked'] + $aged;
foreach (['route-alpha' => 'Route Alpha', 'route-beta' => 'Route Beta', 'route-none' => 'No route here'] as $key => $title) {
    check(Api::track($base + ['key' => $key, 'title' => $title]), 'track an item for the routing regression');
}
ItemWatcher::scan();
foreach (['route-alpha' => $routeA, 'route-beta' => $routeB] as $key => $pid) {
    check(countElementsInTable(Escalation::TABLE, ['dedup_key' => Api::dedup('route-test', 'tracked', $key), 'policies_id' => $pid]) === 1,
        "tracked title selects the expected hierarchy for $key");
}
check(countElementsInTable(Api::TRACKED, ['plugin_key' => 'route-test', 'ref_key' => 'route-none', 'status' => 'active']) === 1,
    'a nonmatching title does not inherit a cached match');
echo "OK tracked routing distinguishes titles and keeps nonmatching items active\n";

$plugin = str_repeat('p', 40);
$type = str_repeat('t', 60);
Policy::saveRule($routeA, ['name' => 'Long API references', 'source' => 'plugin', 'plugin_type' => "$plugin|$type",
    'sla_pct' => 50, 'is_active' => 1]);
$api = ['plugin' => $plugin, 'type' => $type, 'title' => 'Long API reference'] + $aged;
$refs = [str_repeat('x', 110) . 'a', str_repeat('x', 110) . 'b', str_repeat('ç', 125) . 'a', str_repeat('ç', 125) . 'b'];
foreach ($refs as $ref) {
    check(Api::track($api + ['key' => $ref]), 'long API reference persisted');
}
ItemWatcher::scan();
check(countElementsInTable(Api::TRACKED, ['plugin_key' => $plugin, 'type_key' => $type, 'status' => 'opened']) === 4,
    'references beyond both the composition limit and the storage limit get distinct tracking rows');
$ids = [];
foreach ($refs as $ref) {
    $result = Api::escalate($api + ['key' => $ref]);
    check($result && !$result['created'], 'direct opening deduplicates the same tracked reference');
    $ids[] = $result['id'];
}
check(count(array_unique($ids)) === 4, 'long references sharing prefixes got different occurrences');
check(Api::resolve($plugin, $type, $refs[2]) && Escalation::get($ids[2])['state'] === 'resolved'
    && Escalation::get($ids[3])['state'] === 'open'
    && countElementsInTable(Api::TRACKED, ['escalations_id' => $ids[2], 'status' => 'done']) === 1,
    'resolution uses the same long identity and leaves its sibling untouched');
check(Api::dedup('a:b', 'c', 'd') !== Api::dedup('a', 'b', 'c:d'), 'API tuples with embedded delimiters remain distinct');
check(Api::dedup($plugin, $type . 'a', 'same') !== Api::dedup($plugin, $type . 'b', 'same'), 'overlong slugs cannot merge item identities');
foreach (['a', 'b'] as $suffix) {
    Api::registerType($plugin . $suffix, $type, "Overlong plugin $suffix");
    check(Api::escalate(['plugin' => $plugin . $suffix, 'key' => 'same'] + $api) === null,
        'an overlong plugin does not inherit the rule for its prefix');
}
check(countElementsInTable(Api::SOURCES, ['label' => ['Overlong plugin a', 'Overlong plugin b']]) === 2,
    'overlong plugin names with the same prefix register distinct routing keys');
echo "OK API tracks, opens, deduplicates and resolves distinct long and composite references\n";

$legacyRef = str_repeat('l', 105);
$legacyKey = mb_substr("plugin:$plugin:$type:$legacyRef", 0, 190);
[$legacyApi] = Escalation::open(['source' => 'plugin'] + openInput($routeA, $legacyKey));
check(Api::track($api + ['key' => $legacyRef]), 'prepare historical tracking link');
$DB->update(Api::TRACKED, ['status' => 'opened', 'escalations_id' => $legacyApi], ['plugin_key' => $plugin, 'type_key' => $type, 'ref_key' => $legacyRef]);
$legacyRepeat = Api::escalate($api + ['key' => $legacyRef]);
check($legacyRepeat && !$legacyRepeat['created'] && $legacyRepeat['id'] === $legacyApi, 'existing tracked link deduplicates against the historical key');
check(Api::resolve($plugin, $type, $legacyRef) && Escalation::get($legacyApi)['dedup_key'] === $legacyKey
    && Escalation::get($legacyApi)['state'] === 'resolved', 'historical tracked occurrence resolves without rewriting the identifier');
echo "OK historical API key remains recoverable through the existing tracking link\n";

$long = str_repeat('界', 200);
$events = parallel(['event', $routeA, " $long-a "], ['event', $routeA, "$long-a"], ['event', $routeA, "$long-b"]);
$codes = array_column(array_slice($events, 0, 2), 'result');
check(array_sum(array_column($codes, 0)) === 401 && $codes[0][1]['id'] === $codes[1][1]['id']
    && $events[2]['result'][0] === 201 && $events[2]['result'][1]['id'] !== $codes[0][1]['id'],
    'concurrent duplicate long keys serialize without matching a different suffix');
$recoveries = parallel(['recover', 0, " $long-a "], ['recover', 0, "$long-a"]);
$actions = array_map(static fn (array $result): string => $result['result'][1]['action'], $recoveries);
sort($actions);
check($actions === ['ignored', 'resolved'] && Escalation::get($events[2]['result'][1]['id'])['state'] === 'open',
    'long recovery uses the opening identity and resolves once');

$historicalPrefix = str_repeat('h', 190);
[$historical] = Escalation::open(['source' => 'event'] + openInput($routeA, $historicalPrefix));
$event = ['title' => 'Historical event', 'policy_id' => $routeA];
check(EventIntake::handle($event + ['status' => 'OK', 'dedup_key' => "$historicalPrefix-original"])[1]['action'] === 'ignored',
    'a truncated historical prefix is not enough to recover an occurrence');
check(EventIntake::handle($event + ['dedup_key' => " $historicalPrefix "])[1]['id'] === $historical
    && EventIntake::handle($event + ['status' => 'OK', 'dedup_key' => $historicalPrefix])[1]['action'] === 'resolved'
    && Escalation::get($historical)['dedup_key'] === $historicalPrefix,
    'the explicitly supplied persisted identifier still supports deduplication and recovery');

$generated = ['source' => 'legacy-system', 'trigger_id' => 7, 'host' => 'node'];
$oldGeneratedKey = 'evt:' . sha1('legacy-system|7|node');
[$historicalAuto] = Escalation::open(['source' => 'event'] + openInput($routeA, $oldGeneratedKey));
check(EventIntake::handle($event + $generated)[1]['id'] === $historicalAuto
    && EventIntake::handle($event + $generated + ['value' => 0])[1]['action'] === 'resolved',
    'unambiguous historical generated keys remain compatible');
$system = str_repeat('z', 40);
[, $generatedA] = EventIntake::handle($event + ['source' => "$system-a", 'trigger_id' => 'group|7', 'host' => 'node']);
[, $generatedB] = EventIntake::handle($event + ['source' => "$system-b", 'trigger_id' => 'group|7', 'host' => 'node']);
[, $generatedC] = EventIntake::handle($event + ['source' => "$system-a", 'trigger_id' => 'group', 'host' => '7|node']);
check(count(array_unique([$generatedA['id'], $generatedB['id'], $generatedC['id']])) === 3
    && EventIntake::handle(['title' => 'Different recovery title', 'source' => "$system-a", 'trigger_id' => 'group|7', 'host' => 'node', 'value' => 0])[1]['action'] === 'resolved',
    'generated keys keep the full system and tuple boundaries from opening through recovery');
echo "OK webhook keys handle concurrency, long inputs, composite boundaries and historical identifiers\n";

$available = Slo::budget(100, 0, 95.0, 100, 0);
check(abs($available['allowed'] - 5.0) < 0.0001 && abs($available['remaining'] - 100.0) < 0.0001
    && abs($available['compliance'] - 100.0) < 0.0001 && abs($available['burn'] - 0.0) < 0.0001
    && Slo::remainingLabel((float) $available['remaining']) === '100% restante',
    'available error budget keeps 100% remaining');
$partial = Slo::budget(100, 2, 95.0, 100, 2);
check(abs($partial['remaining'] - 60.0) < 0.0001 && abs($partial['compliance'] - 98.0) < 0.0001
    && abs($partial['burn'] - 0.4) < 0.0001
    && Slo::remainingLabel((float) $partial['remaining']) === '60% restante',
    'partially consumed error budget keeps the correct percentage');
$exhausted = Slo::budget(20, 1, 95.0, 20, 1);
check(abs($exhausted['remaining'] - 0.0) < 0.0001 && abs($exhausted['compliance'] - 95.0) < 0.0001
    && abs($exhausted['burn'] - 1.0) < 0.0001
    && Slo::remainingLabel((float) $exhausted['remaining']) === 'esgotado',
    'exactly exhausted error budget is labelled as exhausted');
$over = Slo::budget(2, 1, 95.0, 2, 1);
check(abs($over['remaining'] - (-100.0)) < 0.0001 && abs($over['allowed'] - 0.1) < 0.0001
    && abs($over['compliance'] - 50.0) < 0.0001 && abs($over['burn'] - 10.0) < 0.0001
    && Slo::remainingLabel((float) $over['remaining']) === 'esgotado',
    'over-budget remains clamped and is not displayed as remaining');
echo "OK SLO error budget covers available, partial, exhausted and over-budget states\n";

$sloPolicy = Policy::save(['name' => 'SLO budget regression', 'slo_target' => 95, 'slo_window_days' => 30, 'is_active' => 1]);
[$sloOk] = Escalation::open(['title' => 'SLO budget ok', 'no_start' => true] + openInput($sloPolicy, 'slo-budget-ok'));
[$sloBad] = Escalation::open(['title' => 'SLO budget breached', 'no_start' => true] + openInput($sloPolicy, 'slo-budget-breached'));
[$sloCancelled] = Escalation::open(['title' => 'SLO budget cancelled', 'no_start' => true] + openInput($sloPolicy, 'slo-budget-cancelled'));
$sloNow = date('Y-m-d H:i:s');
$DB->update(Escalation::TABLE, ['state' => 'resolved', 'acked_at' => $sloNow, 'resolved_at' => $sloNow, 'ack_breached' => 0, 'resolve_breached' => 0], ['id' => $sloOk]);
$DB->update(Escalation::TABLE, ['state' => 'resolved', 'acked_at' => $sloNow, 'resolved_at' => $sloNow, 'ack_breached' => 1, 'resolve_breached' => 1], ['id' => $sloBad]);
$DB->update(Escalation::TABLE, ['state' => 'cancelled', 'acked_at' => $sloNow, 'resolved_at' => $sloNow, 'ack_breached' => 1, 'resolve_breached' => 1], ['id' => $sloCancelled]);
$sloStats = Slo::stats(Policy::get($sloPolicy));
check($sloStats['total'] === 2 && $sloStats['ack_breaches'] === 1 && $sloStats['res_breaches'] === 1,
    'SLO stats keep the observed two-escalation scenario and exclude the cancelled row');
check(abs($sloStats['ack']['compliance'] - 50.0) < 0.0001 && abs($sloStats['res']['compliance'] - 50.0) < 0.0001
    && abs($sloStats['ack']['allowed'] - 0.1) < 0.0001 && abs($sloStats['ack']['remaining'] - (-100.0)) < 0.0001
    && abs($sloStats['ack']['burn'] - 10.0) < 0.0001 && $sloStats['status'] === 'estourado'
    && Slo::remainingLabel((float) $sloStats['ack']['remaining']) === 'esgotado',
    'SLO stats reproduce the observed 50/50, 0.1 allowed, 10x burn and exhausted label');
$DB->delete(Log::TABLE, ['escalations_id' => [$sloOk, $sloBad, $sloCancelled]]);
$DB->delete(Escalation::TABLE, ['id' => [$sloOk, $sloBad, $sloCancelled]]);
Policy::delete($sloPolicy);
echo "OK SLO stats exclude cancelled rows and expose the correct budget state\n";

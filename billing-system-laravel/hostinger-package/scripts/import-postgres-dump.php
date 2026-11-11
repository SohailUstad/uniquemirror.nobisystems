#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/PgCopyReader.php';

function stop(string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
function ident(string $name): string { if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) throw new RuntimeException('Unsafe identifier.'); return '`' . $name . '`'; }
function args(array $argv): array {
    $out = ['execute'=>false, 'database'=>null, 'sha256'=>null, 'dump'=>null];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--execute') { $out['execute'] = true; continue; }
        if (str_starts_with($arg, '--database=')) { $out['database'] = substr($arg, 11); continue; }
        if (str_starts_with($arg, '--sha256=')) { $out['sha256'] = strtolower(substr($arg, 9)); continue; }
        if (str_starts_with($arg, '--')) throw new RuntimeException('Unsupported option.');
        if ($out['dump'] !== null) throw new RuntimeException('Only one dump path is allowed.');
        $out['dump'] = $arg;
    }
    if ($out['dump'] === null) throw new RuntimeException('Usage: php import-postgres-dump.php <dump.sql> [--execute --database=NAME --sha256=HEX].');
    if ($out['execute'] && (!is_string($out['database']) || !preg_match('/^[A-Za-z0-9_$-]+$/D', $out['database']) || !is_string($out['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $out['sha256']))) throw new RuntimeException('--execute requires --database and --sha256.');
    if (!$out['execute'] && ($out['database'] !== null || $out['sha256'] !== null)) throw new RuntimeException('--database and --sha256 require --execute.');
    return $out;
}
function mapped(string $table, string $column): string {
    $maps = [
        'clients'=>['phone_number'=>'phoneNumber','client_name'=>'clientName'],
        'client_balances'=>['created_at'=>'createdAt','updated_at'=>'updatedAt','current_balance'=>'currentBalance','current_orders_total_amount'=>'currentOrdersTotalAmount'],
        'orders'=>['total_square_feet'=>'totalSquareFeet','current_stage'=>'currentStage','deleiverd_thorugh'=>'deleiverdThorugh','image_path'=>'imagePath','order_id'=>'orderId','qr_code_path'=>'qrCodePath','worker_rate_per_square_foot'=>'workerRatePerSquareFoot','base_amount'=>'baseAmount','component_amount'=>'componentAmount'],
    ];
    return $maps[$table][$column] ?? $column;
}
function connectApp(string $root): array {
    if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('Laravel application bootstrap files are missing.');
    require $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = $app->make('db')->connection();
    if (!in_array($db->getDriverName(), ['mysql','mariadb'], true)) throw new RuntimeException('Target connection must use MySQL or MariaDB.');
    return [$db->getPdo(), (string)$db->getDatabaseName()];
}
function schema(PDO $pdo, string $database): array {
    $q = $pdo->prepare('SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION');
    $q->execute([$database]); $tables=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) $tables[$c['TABLE_NAME']][$c['COLUMN_NAME']]=$c;
    return $tables;
}
function blocks(string $dump): array {
    $seen=[];
    foreach (PgCopyReader::blocks($dump) as $block) {
        $t=$block['table']; if (isset($seen[$t])) throw new RuntimeException('Duplicate source table block.');
        $seen[$t]=$block['columns']; foreach ($block['rows'] as $_) {}
    }
    if (count($seen)!==58) throw new RuntimeException('Expected exactly 58 public source tables.');
    return $seen;
}
function validate(PDO $pdo, string $database, array $target, array $source): void {
    foreach ($source as $table=>$columns) {
        if (!isset($target[$table])) throw new RuntimeException('Source table has no target table.');
        $mapped=[];
        foreach ($columns as $col) { $to=mapped($table,$col); if (!isset($target[$table][$to])) throw new RuntimeException('Source column mapping is missing.'); if (isset($mapped[$to])) throw new RuntimeException('Duplicate mapped source column.'); $mapped[$to]=true; }
        foreach ($target[$table] as $col=>$meta) if (!isset($mapped[$col]) && stripos((string)$meta['EXTRA'],'auto_increment')===false && (string)$meta['GENERATION_EXPRESSION']==='' && $meta['IS_NULLABLE']==='NO' && $meta['COLUMN_DEFAULT']===null && !(in_array($table,['fcm_device_tokens','registered_client_fcm_device_tokens'],true) && $col==='token_hash')) throw new RuntimeException('Required target column has no source value.');
    }
    foreach ($source as $table=>$_) { $q=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?'); $q->execute([$database,$table]); if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Source target table is absent.'); }
}
function legacyBackfills(string $dump): array {
    $bills=[]; $workers=[]; $existing=[];
    foreach (PgCopyReader::blocks($dump) as $block) {
        $table=$block['table']; $index=array_flip($block['columns']);
        if ($table==='bills') {
            foreach ($block['rows'] as $row) {
                $id=$row[$index['id']] ?? null; if ($id===null) throw new RuntimeException('Bill row has no ID.');
                $changes=[];
                foreach (['version'=>'0','subtotal_amount'=>'0','discount_amount'=>'0'] as $column=>$fallback) {
                    if (isset($index[$column]) && $row[$index[$column]]===null) {
                        $changes[$column]=$column==='subtotal_amount' && isset($index['total_amount']) ? ($row[$index['total_amount']] ?? '0') : $fallback;
                    }
                }
                if (isset($index['status'],$index['confirmed_at'],$index['date']) && $row[$index['status']]==='CONFIRMED' && $row[$index['confirmed_at']]===null) $changes['confirmed_at']=$row[$index['date']];
                if ($changes!==[]) $bills[(string)$id]=$changes;
            }
        } elseif ($table==='workers') {
            foreach ($block['rows'] as $row) {
                $id=$row[$index['id']] ?? null; $user=$row[$index['user_id']] ?? null; $code=$row[$index['code']] ?? null;
                if ($id===null || $user===null) throw new RuntimeException('Worker row has no required identifier.');
                if ($code!==null) { $existing[(string)$user][(string)$code]=true; continue; }
                $workers[(string)$user][]=['id'=>(string)$id];
            }
        }
    }
    $workerCodes=[];
    foreach ($workers as $user=>$rows) {
        usort($rows, static fn(array $a,array $b): int => (int)$a['id'] <=> (int)$b['id']); $next=1;
        foreach ($rows as $worker) {
            while ($next<=99 && isset($existing[$user][$user.'-'.str_pad((string)$next,2,'0',STR_PAD_LEFT)])) ++$next;
            if ($next>99) { $next=0; while ($next<=99 && isset($existing[$user][$user.'-'.str_pad((string)$next,2,'0',STR_PAD_LEFT)])) ++$next; }
            if ($next>99) throw new RuntimeException('No available legacy worker code remains.');
            $code=$user.'-'.str_pad((string)$next,2,'0',STR_PAD_LEFT); $workerCodes[$worker['id']]=$code; $existing[$user][$code]=true; ++$next;
        }
    }
    return ['bills'=>$bills,'workers'=>$workerCodes];
}
function rowValues(string $table, array $columns, array $row, array $target, array $backfills): array {
    $out=[]; $tokenHash=null;
    foreach ($columns as $i=>$column) {
        $to=mapped($table,$column); $v=$row[$i];
        if ($v!==null && in_array($table,['fcm_device_tokens','registered_client_fcm_device_tokens'],true) && $column==='token') $tokenHash=hash('sha256',$v);
        if ($v!==null && isset($target[$table][$to]) && strtolower($target[$table][$to]['DATA_TYPE'])==='tinyint' && in_array($v,['t','f'],true)) $v=$v==='t'?'1':'0';
        $out[$to]=$v;
    }
    $idIndex=array_search('id',$columns,true); $id=$idIndex===false?null:$row[$idIndex];
    if ($table==='bills' && $id!==null && isset($backfills['bills'][(string)$id])) foreach ($backfills['bills'][(string)$id] as $column=>$value) $out[$column]=$value;
    if ($table==='workers' && $id!==null && isset($backfills['workers'][(string)$id])) $out['code']=$backfills['workers'][(string)$id];
    if ($tokenHash!==null) $out['token_hash']=$tokenHash;
    return $out;
}
try {
    $opt=args($argv); $dump=realpath((string)$opt['dump']);
    if ($dump===false || !is_file($dump) || !is_readable($dump)) throw new RuntimeException('Dump file is unavailable.');
    $source=blocks($dump); $backfills=legacyBackfills($dump); [$pdo,$database]=connectApp(dirname(__DIR__,2)); $target=schema($pdo,$database); validate($pdo,$database,$target,$source);
    if (!$opt['execute']) { printf("DRY RUN: validated %d source tables and mappings for database %s; no writes performed.\n",count($source),$database); exit(0); }
    if (!hash_equals((string)$opt['database'],$database)) throw new RuntimeException('Connected database does not match --database.');
    $hash=hash_file('sha256',$dump); if (!is_string($hash) || !hash_equals((string)$opt['sha256'],$hash)) throw new RuntimeException('Dump SHA-256 does not match.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?'); $q->execute([$database,'migrations']); if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Laravel migrations table is missing.');
    $applied=$pdo->query('SELECT migration FROM `migrations`')->fetchAll(PDO::FETCH_COLUMN);
    $expected=[]; foreach (glob(dirname(__DIR__,2).'/database/migrations/*.php') ?: [] as $file) $expected[]=pathinfo($file,PATHINFO_FILENAME);
    if ($expected===[] || array_diff($expected,$applied)!==[]) throw new RuntimeException('Laravel migrations are not fully applied.');
    foreach ($source as $table=>$_) { $q=$pdo->query('SELECT COUNT(*) FROM '.ident($table)); if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Every source target table must be empty before import.'); }
    $pdo->beginTransaction(); $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); $counts=[]; $currentTable=''; $currentRow=0;
    foreach (PgCopyReader::blocks($dump) as $block) {
        $t=$block['table']; $currentTable=$t; $currentRow=0; $cols=$block['columns']; $names=array_map(static fn($c)=>ident(mapped($t,$c)),$cols);
        if (in_array($t,['fcm_device_tokens','registered_client_fcm_device_tokens'],true) && in_array('token',$cols,true)) { $names[]=ident('token_hash'); }
        $stmt=$pdo->prepare('INSERT INTO '.ident($t).' ('.implode(',',$names).') VALUES ('.implode(',',array_fill(0,count($names),'?')).')'); $counts[$t]=0;
        foreach ($block['rows'] as $row) { ++$currentRow; $values=rowValues($t,$cols,$row,$target,$backfills); $stmt->execute(array_values($values)); ++$counts[$t]; }
    }
    foreach ($counts as $t=>$n) { $q=$pdo->query('SELECT COUNT(*) FROM '.ident($t)); if ((int)$q->fetchColumn()!==$n) throw new RuntimeException('Imported row count validation failed.'); }
    $fk=$pdo->prepare('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL'); $fk->execute([$database]);
    $orphans=[];
    foreach ($fk->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $sql='SELECT COUNT(*) FROM '.ident($f['TABLE_NAME']).' c LEFT JOIN '.ident($f['REFERENCED_TABLE_NAME']).' p ON c.'.ident($f['COLUMN_NAME']).'=p.'.ident($f['REFERENCED_COLUMN_NAME']).' WHERE c.'.ident($f['COLUMN_NAME']).' IS NOT NULL AND p.'.ident($f['REFERENCED_COLUMN_NAME']).' IS NULL';
        $orphanCount=(int)$pdo->query($sql)->fetchColumn();
        if ($orphanCount>0) $orphans[]="{$f['TABLE_NAME']}.{$f['COLUMN_NAME']} -> {$f['REFERENCED_TABLE_NAME']}.{$f['REFERENCED_COLUMN_NAME']} ($orphanCount rows)";
    }
    if ($orphans!==[]) throw new RuntimeException('Foreign key orphans: '.implode('; ',$orphans));
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); $pdo->commit(); printf("IMPORT COMPLETE: %d tables, %d rows.\n",count($counts),array_sum($counts));
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) { if ($pdo->inTransaction()) $pdo->rollBack(); try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {} }
    if ($e instanceof PDOException) {
        $location = ($currentTable ?? '') !== '' ? " in {$currentTable} row ".($currentRow ?? 0) : '';
        stop('Database import failed'.$location.' (SQLSTATE '.(string)$e->getCode().'); details suppressed.');
    }
    stop($e instanceof RuntimeException ? $e->getMessage() : 'Import failed; details suppressed.');
}

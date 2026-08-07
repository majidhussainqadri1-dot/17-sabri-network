<?php
declare(strict_types=1);
$root=dirname(__DIR__);$src=file_get_contents($root.'/includes/class-sn-file-transfer-part-3.php');$fails=[];$checks=0;
function fpr2(bool $c,string $m):void{global $fails,$checks;$checks++;if(!$c)$fails[]=$m;}
fpr2(str_contains($src,'random_bytes(12)'),'Each upload attempt gets a cryptographically random storage suffix.');
fpr2(str_contains($src,"'-' . \$attempt . '.snc'"),'Concurrent attempts do not share a deterministic encrypted chunk path.');
fpr2(str_contains($src,'UNIQUE')||str_contains(file_get_contents($root.'/includes/class-sn-file-transfer-part-1.php'),'UNIQUE KEY transfer_chunk'),'Chunk ownership is database-unique per transfer/index.');
fpr2(str_contains($src,"@unlink(self::storage_root() . '/' . \$storage_key)"),'A loser deletes only its own attempt path.');
fpr2(str_contains($src,'(int) $race->byte_count === $bytes')&&str_contains($src,'hash_equals((string) $race->sha256, $sha)'),'Retry winner validation binds both length and checksum.');
fpr2(str_contains($src,'received_chunks=received_chunks+1,received_bytes=received_bytes+%d'),'Only the committed winner advances server-side counters.');
fpr2(str_contains($src,'secure_random_unavailable'),'Randomness failure is fail-closed.');
fpr2(!str_contains($src,"str_pad((string) \$index, 6, '0', STR_PAD_LEFT) . '.snc'"),'The destructive deterministic path form is gone.');
if($fails){fwrite(STDERR,"Four-plan review 2 failures (".count($fails)."/$checks):\n - ".implode("\n - ",$fails)."\n");exit(1);}echo "Four-plan review 2 transfer concurrency: PASS ($checks checks)\n";

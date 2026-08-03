<?php
/** Fresh/adversarial contracts for follow, profile actions and relationship races. */
declare(strict_types=1);
$root=dirname(__DIR__);$failures=[];$checks=0;
function ra_check(bool $c,string $m):void{global $failures,$checks;$checks++;if(!$c)$failures[]=$m;}
function ra_content(string $f):string{global $root;return (string)file_get_contents($root . '/' . $f);}
$r=ra_content('includes/class-sn-relationships.php');$p=ra_content('includes/class-sn-policy.php');$rest=ra_content('includes/class-sn-rest.php');$db=ra_content('includes/class-sn-db.php');$js=ra_content('assets/js/network.js');$files=ra_content('includes/class-sn-private-files.php');
ra_check(str_contains($r,'viewer_id === $target_id'),'Self-follow/profile-action state must be rejected.');
ra_check(str_contains($p,'$actor_id === $target_id'),'Self-follow authorization must be rejected.');
ra_check(str_contains($p,'!get_user_by(\'id\', $target_id)'),'Nonexistent follow targets must be rejected.');
ra_check(str_contains($p,'is_suspended($actor_id)'),'Suspended actor must be rejected.');
ra_check(str_contains($p,'is_suspended($target_id)'),'Suspended target must be rejected.');
ra_check(str_contains($p,'SN_DB::is_blocked($actor_id, $target_id)'),'Block state must override follow availability.');
ra_check(str_contains($p,'$actor_age === \'unknown\''),'Unknown actor age must be handled.');
ra_check(str_contains($p,'$target_age === \'unknown\''),'Unknown target age must be handled.');
ra_check(str_contains($p,'$actor_age === \'minor\''),'Minor actor state must be handled.');
ra_check(str_contains($p,'$target_age === \'minor\''),'Minor target state must be handled.');
ra_check(str_contains($p,'!in_array($visibility, [\'everyone\', \'contacts\', \'nobody\'], true)'),'Invalid follow privacy values must be normalized conservatively.');
ra_check(str_contains($r,'return self::project($existing, true)'),'Repeated follow retries must return the canonical record.');
ra_check(str_contains($r,'$race = SN_DB::follow_record'),'Unique-key races must re-read canonical state.');
ra_check(str_contains($r,'WHERE id=%d AND follower_id=%d AND version=%d'),'Unfollow must bind actor and expected version.');
ra_check(str_contains($r,'(int) $row->followed_id !== $target_id'),'Only the follow target may decide a pending request.');
ra_check(str_contains($r,'(string) $row->status !== \'pending\''),'Only pending follow requests may be decided.');
ra_check(str_contains($r,'SN_DB::is_blocked((int) $row->follower_id, $target_id)'),'Block changes must invalidate pending decisions.');
ra_check(str_contains($r,'count($rows) > $limit'),'Pagination must fetch a lookahead row.');
ra_check(str_contains($r,'array_slice($rows, 0, $limit)'),'Pagination must remain bounded.');
ra_check(str_contains($r,'(int) ($data[\'user_id\'] ?? 0) !== $user_id'),'Cursor must be user-bound.');
ra_check(str_contains($r,'(string) ($data[\'scope\'] ?? \'\') !== $scope'),'Cursor must be scope-bound.');
ra_check(str_contains($rest,'$action === \'unfollow\''),'Follow endpoint must support unfollow.');
ra_check(str_contains($rest,'get_param(\'version\')'),'Unfollow/decisions must carry optimistic version.');
ra_check(str_contains($rest,'$action === \'follow\''),'Follow endpoint must support follow.');
ra_check(str_contains($rest,'invalid_follow_action'),'Follow endpoint must allowlist actions.');
ra_check(str_contains($rest,'!is_wp_error($relationship)'),'Directory relationship projection must suppress invalid states.');
ra_check(str_contains($rest,'SN_DB::is_blocked($viewer_id, $id)'),'Directory must not disclose blocked accounts.');
ra_check(str_contains($rest,'SELECT * FROM $conversations WHERE id=%d FOR UPDATE'),'Group addition must lock the conversation before capacity decisions.');
ra_check(str_contains($rest,'$actor->left_at !== null'),'Removed administrator membership must not add users.');
ra_check(str_contains($rest,'$active_count >= $limit'),'Member cap must count active members.');
ra_check(str_contains($rest,'member_write_failed'),'Member write failures must rollback.');
ra_check(str_contains($db,'UNIQUE KEY follower_followed'),'Concurrent duplicate follows must be constrained in SQL.');
ra_check(str_contains($js,'follow.state === \'pending\' ? \'Cancel request\' : \'Unfollow\''),'UI must not mislabel a pending follow request.');
ra_check(str_contains($files,'deleted_at IS NOT NULL'),'Byte retry must only target revoked attachments.');
ra_check(str_contains($files,'$attempts < 5'),'Private-byte retry must be bounded.');
ra_check(!str_contains($r,'OFFSET '),'Follow pagination must avoid unstable offset pagination.');
ra_check(strpos($files,"['deleted_at' => current_time",strpos($files,'function delete')) < strpos($files,'@unlink',strpos($files,'function delete')),'Private access must be revoked before byte deletion.');
if($failures){fwrite(STDERR,"Relationship adversarial failures (".count($failures)."/$checks):\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "Relationship adversarial contracts: PASS ($checks checks)\n";

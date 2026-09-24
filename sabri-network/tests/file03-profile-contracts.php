<?php
$src=file_get_contents(dirname(__DIR__).'/sabri-network.php');
foreach(['sabri_network_contact_claim_v1','sabri_network_message_profile_url','sabri_file17_profile_contact_relay_v1','file03_contact_claim','file03_message_profile_url','file03_contact_relay','address_hidden'] as $t){if(strpos($src,$t)===false){fwrite(STDERR,"Missing File03 network contract: $t\n");exit(1);}}
if(!preg_match("/['\"]address_hidden['\"]\s*=>\s*true/", $src)){fwrite(STDERR,"Contact relay privacy guard missing\n");exit(1);}
echo "File17 -> File03 profile communication contracts: PASS\n";

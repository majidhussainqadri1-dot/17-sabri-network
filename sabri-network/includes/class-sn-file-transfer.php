<?php
defined('ABSPATH') || exit;

/** Verified-user, private, encrypted and resumable File-17 transfer workflow (maximum 1 GiB per file). */
require_once __DIR__ . '/class-sn-file-transfer-part-1.php';
require_once __DIR__ . '/class-sn-file-transfer-part-2.php';
require_once __DIR__ . '/class-sn-file-transfer-part-3.php';
require_once __DIR__ . '/class-sn-file-transfer-part-4.php';
require_once __DIR__ . '/class-sn-file-transfer-part-5.php';
require_once __DIR__ . '/class-sn-file-transfer-part-6.php';
require_once __DIR__ . '/class-sn-file-transfer-part-7.php';
require_once __DIR__ . '/class-sn-file-transfer-part-8.php';

final class SN_File_Transfer {

    public const MAX_FILE_BYTES = 1073741824;
    private const SCHEMA_VERSION = '1.0.0';
    private const PAGE_OWNER_META = '_sn_file_transfer_owned';
    private const MIN_CHUNK_BYTES = 1048576;
    private const MAX_CHUNK_BYTES = 16777216;
    private const DEFAULT_CHUNK_BYTES = 8388608;
    private const MAX_RECIPIENTS = 256;
    private const GRANT_TTL = 600;
    private const PRIVACY_ERASE_PAGE_SIZE = 100;
    use SN_File_Transfer_Part_1, SN_File_Transfer_Part_2, SN_File_Transfer_Part_3, SN_File_Transfer_Part_4, SN_File_Transfer_Part_5, SN_File_Transfer_Part_6, SN_File_Transfer_Part_7, SN_File_Transfer_Part_8;
}

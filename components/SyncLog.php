<?php
namespace k7zz\humhub\civicrm\components;

use Yii;
use yii\log\Logger;

final class SyncLog
{
    public const LOG_CATEGORY_SYNC = 'civicrm.sync';

    public static function info(string $msg, array $ctx = []): void
    {
        Yii::getLogger()->log(self::payload($msg, $ctx), Logger::LEVEL_INFO, self::LOG_CATEGORY_SYNC);
    }

    public static function warning(string $msg, array $ctx = []): void
    {
        Yii::getLogger()->log(self::payload($msg, $ctx), Logger::LEVEL_WARNING, self::LOG_CATEGORY_SYNC);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        Yii::getLogger()->log(self::payload($msg, $ctx), Logger::LEVEL_ERROR, self::LOG_CATEGORY_SYNC);
    }

    private static function payload(string $msg, array $ctx): array|string
    {
        // Arrays werden von Yii automatisch JSON-serialisiert
        if (!is_array($ctx) || empty($ctx)) {
            return $msg;
        }
        return ['msg' => $msg, 'ctx' => $ctx];
    }
}

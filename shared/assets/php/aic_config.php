<?php
/**
 * aic.asobi.info 連携設定
 * クロスサイトJWT認証で使用する共有シークレット
 * aic 側の .env JWT_SECRET と同じ値にすること
 */

define('AIC_SHARED_SECRET', 'fc8a8081db151abd74523baa6d1630c7478f6cef9145428a895c3269568c5920');
define('AIC_CALLBACK_URL',  'https://aic.asobi.info/api/auth/asobi/callback');

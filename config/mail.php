<?php
if (!defined('MAIL_HOST')) {
    define('MAIL_HOST', getenv('MAIL_HOST') ?: '');
}
if (!defined('MAIL_PORT')) {
    define('MAIL_PORT', getenv('MAIL_PORT') ?: 587);
}
if (!defined('MAIL_USERNAME')) {
    define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
}
if (!defined('MAIL_PASSWORD')) {
    define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: '');
}
if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: '');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Suave Urban Studio');
}
if (!defined('MAIL_ENCRYPTION')) {
    define('MAIL_ENCRYPTION', getenv('MAIL_ENCRYPTION') ?: 'tls');
}
